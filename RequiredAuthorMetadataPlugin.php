<?php

/**
 * @file plugins/generic/requiredAuthorMetadata/RequiredAuthorMetadataPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RequiredAuthorMetadataPlugin
 *
 * @brief Lets a journal require the affiliation and the biography of every
 *        contributor of a submission, each one on its own, and refuse to let
 *        the submission be completed while either is missing.
 *
 * The core already does this for one author field — the competing interests
 * statement, through the `requireAuthorCompetingInterests` setting of the
 * journal, checked in Repo::author()->validate(). This plugin is the same idea
 * for the affiliation and the biography, plus the gate at the end of the
 * submission wizard, and it is all done through hooks: no core template is
 * replaced and no core class is extended.
 *
 * Three hooks, in the order the author meets them:
 *
 * 1) TemplateManager::display -> draws the required mark on the label of each
 *                               field the journal requires, in the language of
 *                               the submission. Marking the field through the
 *                               form (`isRequired`) cannot be done: a
 *                               multilingual field is then required by the
 *                               browser in EVERY language of the journal, while
 *                               only the language of the submission is required
 *                               here — and the affiliations field of PKP 3.5
 *                               ignores that property anyway;
 * 2) Author::validate        -> refuses to save a contributor without them.
 *                               This is the guarantee: the mark is a courtesy,
 *                               and the REST endpoint is what stores the data;
 * 3) Submission::validateSubmit
 *                            -> refuses to complete the submission, naming every
 *                               contributor who is missing each field.
 *
 * Whoever runs the journal can be left out of all three (`editorsExempt`, on by
 * default): a manager or a section editor keeps the autonomy to record and to
 * correct a submission with these fields still missing.
 */

namespace APP\plugins\generic\requiredAuthorMetadata;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use PKP\components\forms\publication\ContributorForm;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\template\PKPTemplateManager;

class RequiredAuthorMetadataPlugin extends GenericPlugin
{
    /**
     * The settings of the journal and what they do while nothing was saved yet:
     * nothing is required until a journal asks for it, and whoever runs the
     * journal is left out of it.
     */
    public const DEFAULTS = [
        'requireFamilyName' => false,
        'requireAffiliation' => false,
        'requireBiography' => false,
        'requireOnSubmit' => false,
        'editorsExempt' => true,
    ];

    /**
     * Who is left out while `editorsExempt` is on: the roles that decide about
     * the submission. An assistant follows the rule like everybody else.
     */
    public const EXEMPT_ROLES = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];

    /**
     * Templates that mount the contributors panel, where the affiliation label
     * has to carry the required mark.
     */
    public const TEMPLATES_WITH_CONTRIBUTORS = [
        'dashboard/editors.tpl',
        'submission/wizard.tpl',
    ];

    /**
     * The fields this plugin can require, in the order the contributor form
     * shows them: the setting each one is read from, and the name it has in the
     * form and in the errors.
     */
    public const FIELDS = ['familyName' => 'familyName', 'affiliation' => 'affiliations', 'biography' => 'biography'];

    /**
     * Of those, the ones that are one text per language: they are required in
     * the language of the submission, which is what the core does with every
     * multilingual field it requires.
     */
    public const TEXT_FIELDS = ['familyName' => 'familyName', 'biography' => 'biography'];

    /**
     * Register the plugin and, where it is enabled, its hooks.
     *
     * @param string $category
     * @param string $path
     * @param null|int $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || Application::isUnderMaintenance() || !$this->getEnabled($mainContextId)) {
            return $success;
        }

        Hook::add('TemplateManager::display', $this->markRequiredLabels(...));
        Hook::add('Author::validate', $this->validateAuthor(...));
        Hook::add('Submission::validateSubmit', $this->validateSubmit(...));

        return $success;
    }

    /**
     * Name shown in the plugins list.
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.requiredAuthorMetadata.displayName');
    }

    /**
     * Description shown in the plugins list.
     */
    public function getDescription(): string
    {
        return __('plugins.generic.requiredAuthorMetadata.description');
    }

    /**
     * A journal setting, with its default while it was never saved. The plugin
     * never writes a setting on its own: a journal that enabled it and chose
     * nothing requires nothing.
     */
    public function getFlag(?int $contextId, string $name): bool
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            return false;
        }
        $value = $contextId === null ? null : $this->getSetting($contextId, $name);

        return $value === null || $value === '' ? self::DEFAULTS[$name] : (bool) $value;
    }

    /**
     * Whether the person making the request is left out of the rules of this
     * journal.
     */
    public function isExempt(?int $contextId): bool
    {
        if ($contextId === null || !$this->getFlag($contextId, 'editorsExempt')) {
            return false;
        }
        $user = Application::get()->getRequest()->getUser();

        return $user && $user->hasRole(self::EXEMPT_ROLES, $contextId);
    }

    /**
     * Hook TemplateManager::display — draws the required mark on the label of
     * each field the journal requires, in the language of the submission.
     *
     * Two reasons for doing it here instead of through the form: a multilingual
     * field marked as required is required by the browser in every language (see
     * markRequiredFields()), and the affiliations field of PKP 3.5 draws its own
     * heading and ignores what the form says — the prop is not even declared by
     * the component and ends up as an attribute on the element.
     *
     * @param array $args [$templateMgr, &$template]
     */
    public function markRequiredLabels(string $hookName, array $args): bool
    {
        $templateMgr = $args[0] ?? null;
        $template = $args[1] ?? '';
        if (!$templateMgr instanceof PKPTemplateManager || !in_array($template, self::TEMPLATES_WITH_CONTRIBUTORS)) {
            return Hook::CONTINUE;
        }

        $contextId = Application::get()->getRequest()->getContext()?->getId();
        if ($contextId === null || $this->isExempt($contextId)) {
            return Hook::CONTINUE;
        }

        // The colour is the one the application uses for every other required
        // field (.pkpFormFieldLabel__required).
        $mark = '::after { content: " *"; color: #d00a6c; }';
        $rules = [];
        if ($this->getFlag($contextId, 'requireAffiliation')) {
            $rules[] = '#contributor-affiliations > .pkpFormField__heading > .pkpFormFieldLabel' . $mark;
        }

        $locale = $this->submissionLocaleOfRequest();
        foreach (self::TEXT_FIELDS as $setting => $fieldName) {
            if (!$this->getFlag($contextId, 'require' . ucfirst($setting))) {
                continue;
            }
            $rules[] = $locale === null
                // Without a submission to read the language from, the mark goes
                // on the language being shown, which is the one being edited.
                ? '.pkpFormGroup__locale--isVisible label[for^="contributor-' . $fieldName . '-control-"]' . $mark
                : 'label[for="contributor-' . $fieldName . '-control-' . $locale . '"]' . $mark;
        }

        if ($rules) {
            $templateMgr->addStyleSheet(
                'requiredAuthorMetadataLabels',
                implode(' ', $rules),
                ['inline' => true, 'contexts' => ['backend']]
            );
        }

        return Hook::CONTINUE;
    }

    /**
     * The language of the submission being edited, as the form writes it into
     * the id of a field, or null where the page is not about one submission.
     */
    private function submissionLocaleOfRequest(): ?string
    {
        $request = Application::get()->getRequest();
        foreach (['id', 'submissionId', 'workflowSubmissionId'] as $name) {
            $submissionId = (int) $request->getUserVar($name);
            if (!$submissionId) {
                continue;
            }
            $submission = Repo::submission()->get($submissionId);
            $locale = $submission?->getData('locale');
            if ($locale) {
                // The form builds the id of the control with the locale, and
                // anything that is not a letter, a digit or an underscore is
                // replaced there (es@formal becomes es_formal).
                return preg_replace('/[^A-Za-z0-9_]/', '_', $locale);
            }
        }

        return null;
    }

    /**
     * Hook Author::validate — a contributor is not saved while a field the
     * journal requires is missing.
     *
     * The hook carries neither the submission nor the context, so the journal is
     * reached through the publication being edited.
     *
     * @param array $args [&$errors, $author, $props, $allowedLocales, $primaryLocale]
     */
    public function validateAuthor(string $hookName, array $args): bool
    {
        $errors = &$args[0];
        $author = $args[1] ?? null;
        $props = $args[2] ?? [];
        $primaryLocale = $args[4] ?? null;

        $contextId = $this->contextIdOf($props['publicationId'] ?? $author?->getData('publicationId'));
        if ($contextId === null || $this->isExempt($contextId)) {
            return Hook::CONTINUE;
        }

        // A save that carries no key for the field is still a save: what counts
        // is what the contributor would be left with.
        if ($this->getFlag($contextId, 'requireAffiliation')) {
            $affiliations = array_key_exists('affiliations', $props)
                ? $props['affiliations']
                : ($author?->getAffiliations() ?? []);
            if (self::affiliationsMissing($affiliations)) {
                $errors['affiliations'] = [__('plugins.generic.requiredAuthorMetadata.error.affiliation.required')];
            }
        }

        foreach (self::TEXT_FIELDS as $setting => $fieldName) {
            if (!$primaryLocale || !$this->getFlag($contextId, 'require' . ucfirst($setting))) {
                continue;
            }
            $value = array_key_exists($fieldName, $props)
                ? $props[$fieldName]
                : ($author?->getData($fieldName) ?? []);
            if (self::textMissing($value, $primaryLocale)) {
                // A multilingual field carries its errors by locale, which is how
                // the core formats them before this hook runs.
                $errors[$fieldName][$primaryLocale] = [__('plugins.generic.requiredAuthorMetadata.error.' . $setting . '.required')];
            }
        }

        return Hook::CONTINUE;
    }

    /**
     * Hook Submission::validateSubmit — the submission cannot be completed while
     * a contributor is missing a field the journal requires.
     *
     * @param array $args [&$errors, $submission, $context]
     */
    public function validateSubmit(string $hookName, array $args): bool
    {
        $errors = &$args[0];
        $submission = $args[1] ?? null;
        $context = $args[2] ?? Application::get()->getRequest()->getContext();
        $contextId = $context?->getId();

        if (!$submission || $contextId === null || !$this->getFlag($contextId, 'requireOnSubmit') || $this->isExempt($contextId)) {
            return Hook::CONTINUE;
        }

        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            return Hook::CONTINUE;
        }

        $locale = $submission->getData('locale');
        $missing = array_fill_keys(array_keys(self::FIELDS), []);
        foreach (Repo::author()->getCollector()->filterByPublicationIds([$publication->getId()])->getMany() as $author) {
            $name = $author->getFullName(false) ?: __('common.none');
            if ($this->getFlag($contextId, 'requireAffiliation') && self::affiliationsMissing($author->getAffiliations())) {
                $missing['affiliation'][] = $name;
            }
            foreach (self::TEXT_FIELDS as $setting => $fieldName) {
                if ($this->getFlag($contextId, 'require' . ucfirst($setting)) && self::textMissing($author->getData($fieldName), $locale)) {
                    $missing[$setting][] = $name;
                }
            }
        }

        foreach ($missing as $field => $names) {
            if (!$names) {
                continue;
            }
            // The key the core uses for its own contributor errors, so that the
            // message is shown in the contributors panel of the review step —
            // and appended, never assigned, so that it lives beside whatever
            // another plugin has already held against the same submission.
            $errors['contributors'] ??= [];
            $errors['contributors'][] = __(
                'plugins.generic.requiredAuthorMetadata.error.' . $field . '.onSubmit',
                ['names' => implode(__('common.commaListSeparator'), $names)]
            );
        }

        return Hook::CONTINUE;
    }

    /**
     * The journal a publication belongs to, or null where it cannot be told.
     */
    private function contextIdOf(mixed $publicationId): ?int
    {
        if ($publicationId) {
            $publication = Repo::publication()->get((int) $publicationId);
            $submission = $publication ? Repo::submission()->get((int) $publication->getData('submissionId')) : null;
            if ($submission) {
                return (int) $submission->getData('contextId');
            }
        }

        return Application::get()->getRequest()->getContext()?->getId();
    }

    /**
     * Whether a contributor is left without an affiliation. An entry that has
     * neither a name in any language nor an organization identifier is not an
     * affiliation.
     *
     * @param array $affiliations Affiliation objects, or the arrays the API sends
     */
    public static function affiliationsMissing(array $affiliations): bool
    {
        foreach ($affiliations as $affiliation) {
            $ror = is_array($affiliation) ? ($affiliation['ror'] ?? null) : $affiliation->getRor();
            if (trim((string) $ror) !== '') {
                return false;
            }
            $names = is_array($affiliation) ? ($affiliation['name'] ?? []) : $affiliation->getName();
            foreach ((array) $names as $name) {
                if (trim((string) $name) !== '') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether a contributor is left without one of the texts in the language of
     * the submission. The biography is rich text, so an empty paragraph is as
     * empty as an empty string; a name of spaces is no name either.
     *
     * @param array|string|null $text one text per language, or a single one
     */
    public static function textMissing(mixed $text, string $locale): bool
    {
        $value = is_array($text) ? ($text[$locale] ?? '') : (string) $text;
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // A non-breaking space is what a rich text editor leaves behind.
        return trim(str_replace("\u{00A0}", ' ', $text)) === '';
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $router = $request->getRouter();

        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ] : [],
            parent::getActions($request, $actionArgs)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $context = $request->getContext();
        if (!$context) {
            return new JSONMessage(false);
        }

        $form = new RequiredAuthorMetadataSettingsForm($this, $context);
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }
        $form->execute();

        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId());

        return new JSONMessage(true);
    }
}
