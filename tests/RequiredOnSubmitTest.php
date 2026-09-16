<?php

/**
 * @file plugins/generic/requiredAuthorMetadata/tests/RequiredOnSubmitTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RequiredOnSubmitTest
 *
 * @brief The submission cannot be completed while a contributor is missing what
 *        the journal requires — and the editor keeps the autonomy to complete
 *        it anyway.
 *
 *        The core's own validation of the last step is run against a real
 *        database, which is what the wizard calls when the author presses
 *        Submit. The test creates and deletes its own submission, puts back the
 *        settings it changed, and skips itself where the installation looks
 *        like a live site.
 */

namespace APP\plugins\generic\requiredAuthorMetadata\tests;

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\plugins\generic\requiredAuthorMetadata\RequiredAuthorMetadataPlugin;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPRequest;
use PKP\core\Registry;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\tests\PKPTestCase;
use PKP\userGroup\UserGroup;

class RequiredOnSubmitTest extends PKPTestCase
{
    private const CONTEXT_ID = 1;
    private const MARKER = '[RAM-TESTS]';
    private const PRODUCTION_LOOKS_LIKE = 100;

    private array $createdSubmissions = [];
    /** The settings as they were before the test, to be put back. */
    private array $savedSettings = [];
    private ?RequiredAuthorMetadataPlugin $plugin = null;

    /**
     * The plugin is registered once for the whole class: a hook added again in
     * the same process would answer twice, and every message would be doubled.
     */
    private static ?RequiredAuthorMetadataPlugin $registered = null;
    private static bool $switchedOn = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Application::getContextDAO()->getById(self::CONTEXT_ID)) {
            $this->markTestSkipped('context ' . self::CONTEXT_ID . ' does not exist');
        }
        $published = DB::table('submissions')->where('status', Submission::STATUS_PUBLISHED)->count();
        if ($published > self::PRODUCTION_LOOKS_LIKE) {
            $this->markTestSkipped('this installation has ' . $published . ' published submissions: it looks like a live site');
        }

        $this->pinContext();
        $this->plugin = $this->loadPlugin();
    }

    protected function tearDown(): void
    {
        $nobody = null;
        Registry::set('user', $nobody);
        foreach ($this->savedSettings as $name => $value) {
            $this->plugin?->updateSetting(self::CONTEXT_ID, $name, $value === null ? 0 : $value, 'bool');
        }
        foreach ($this->createdSubmissions as $id) {
            if ($submission = Repo::submission()->get($id)) {
                Repo::submission()->delete($submission);
            }
        }
        $this->savedSettings = [];
        $this->createdSubmissions = [];
        parent::tearDown();
    }

    /** There is no URL on the command line, so the context is pinned on the router. */
    private function pinContext(): void
    {
        $request = Application::get()->getRequest();
        $router = new class () extends PageRouter {
            public $pinned;

            public function getContext(PKPRequest $request, bool $forceReload = false): ?\PKP\context\Context
            {
                return $this->pinned;
            }
        };
        $router->setApplication(Application::get());
        $router->pinned = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $request->setRouter($router);
    }

    /**
     * The plugin with its listeners attached, as the application loads it. Where
     * it is installed but switched off, it is switched on for the test and put
     * back afterwards, so that the rule is checked and not skipped.
     */
    private function loadPlugin(): RequiredAuthorMetadataPlugin
    {
        if (self::$registered) {
            return self::$registered;
        }

        // From disk, not from the database: a plugin that was never switched on
        // is not in the enabled list, and this test switches it on itself.
        PluginRegistry::loadCategory('generic', false, self::CONTEXT_ID);
        /** @var ?RequiredAuthorMetadataPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('generic', 'requiredauthormetadataplugin');
        if (!$plugin) {
            $this->markTestSkipped('the plugin is not installed here');
        }
        if (!$plugin->getEnabled(self::CONTEXT_ID)) {
            $plugin->updateSetting(self::CONTEXT_ID, 'enabled', 1, 'bool');
            self::$switchedOn = true;
            // Only now does register() attach the hooks.
            $plugin = new RequiredAuthorMetadataPlugin();
            $plugin->register('generic', 'plugins/generic/requiredAuthorMetadata', self::CONTEXT_ID);
        }

        return self::$registered = $plugin;
    }

    /** The journal is left switched off again if it was this test that switched it on. */
    public static function tearDownAfterClass(): void
    {
        if (self::$switchedOn && self::$registered) {
            self::$registered->updateSetting(self::CONTEXT_ID, 'enabled', 0, 'bool');
        }
        self::$registered = null;
        self::$switchedOn = false;
        parent::tearDownAfterClass();
    }

    /** Changes a setting, keeping what it was to put it back in tearDown(). */
    private function set(string $name, bool $value): void
    {
        if (!array_key_exists($name, $this->savedSettings)) {
            $this->savedSettings[$name] = $this->plugin->getSetting(self::CONTEXT_ID, $name);
        }
        $this->plugin->updateSetting(self::CONTEXT_ID, $name, $value ? 1 : 0, 'bool');
    }

    /** The first section of the context, or none where the application allows it. */
    private function firstSectionId(): ?int
    {
        $id = Repo::section()->getCollector()->filterByContextIds([self::CONTEXT_ID])->getIds()->first();

        return $id === null ? null : (int) $id;
    }

    /**
     * A submission waiting for its last step, with one contributor who has
     * neither an affiliation nor a biography.
     */
    private function submissionAwaitingSubmit(string $givenName = 'Ana', ?string $familyName = 'Contributor'): Submission
    {
        $context = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $userGroup = UserGroup::withContextIds([self::CONTEXT_ID])->withRoleIds([Role::ROLE_ID_AUTHOR])->first();
        $this->assertNotNull($userGroup, 'the context has no author role');

        $submission = Repo::submission()->newDataObject([
            'contextId' => self::CONTEXT_ID,
            'status' => Submission::STATUS_QUEUED,
            'submissionProgress' => 'review',
            'stageId' => WORKFLOW_STAGE_ID_SUBMISSION,
            'locale' => 'en',
        ]);
        $publication = Repo::publication()->newDataObject([
            'title' => ['en' => self::MARKER . ' the contributor metadata is required'],
            // A journal files the submission under a section; a press takes it
            // without a series.
            Application::getSectionIdPropName() => $this->firstSectionId(),
            'locale' => 'en',
            'status' => Submission::STATUS_QUEUED,
        ]);
        $submissionId = Repo::submission()->add($submission, $publication, $context);
        $this->createdSubmissions[] = $submissionId;

        $submission = Repo::submission()->get($submissionId);
        Repo::author()->add(Repo::author()->newDataObject([
            'publicationId' => $submission->getCurrentPublication()->getId(),
            'givenName' => ['en' => $givenName],
            'familyName' => ['en' => (string) $familyName],
            'userGroupId' => $userGroup->id,
            'seq' => 0,
            'includeInBrowse' => true,
            'email' => strtolower($givenName) . '.' . time() . '@example.invalid',
            'country' => 'BR',
        ]));

        return Repo::submission()->get($submissionId);
    }

    /**
     * What the core answers the wizard about the contributors.
     *
     * Every plugin writes under the same key, so the assertions below are made
     * against the exact message this one produces — living beside what another
     * plugin has to say is the point, and there is a test of its own for that.
     *
     * @return string[]
     */
    private function contributorErrors(Submission $submission): array
    {
        $context = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $errors = Repo::submission()->validateSubmit($submission, $context);

        return array_values((array) ($errors['contributors'] ?? []));
    }

    /** The message this plugin produces for one field and one contributor. */
    private function message(string $field, string $names): string
    {
        return __('plugins.generic.requiredAuthorMetadata.error.' . $field . '.onSubmit', ['names' => $names]);
    }

    public function testASubmissionIsRefusedWhileAContributorHasNoAffiliation(): void
    {
        $this->set('requireAffiliation', true);
        $this->set('requireBiography', false);
        $this->set('requireOnSubmit', true);
        $submission = $this->submissionAwaitingSubmit('Ana');

        $errors = $this->contributorErrors($submission);
        $expected = $this->message('affiliation', 'Ana Contributor');

        $this->assertContains($expected, $errors, 'the contributor has to be named: ' . implode(' | ', $errors));
        $this->assertStringNotContainsString('##', $expected, 'the message has to be translated');
    }

    public function testEachFieldIsHeldAgainstTheSubmissionOnItsOwn(): void
    {
        $this->set('requireAffiliation', true);
        $this->set('requireBiography', true);
        $this->set('requireOnSubmit', true);
        $submission = $this->submissionAwaitingSubmit('Bruno');

        $errors = $this->contributorErrors($submission);

        // Two rules, two messages: one does not swallow the other.
        $this->assertContains($this->message('affiliation', 'Bruno Contributor'), $errors);
        $this->assertContains($this->message('biography', 'Bruno Contributor'), $errors);

        // With only the biography required, only that one is left.
        $this->set('requireAffiliation', false);
        $left = $this->contributorErrors($submission);
        $this->assertNotContains($this->message('affiliation', 'Bruno Contributor'), $left);
        $this->assertContains($this->message('biography', 'Bruno Contributor'), $left);
    }

    public function testASubmissionIsRefusedWhileAContributorHasNoFamilyName(): void
    {
        $this->set('requireFamilyName', true);
        $this->set('requireAffiliation', false);
        $this->set('requireBiography', false);
        $this->set('requireOnSubmit', true);
        $submission = $this->submissionAwaitingSubmit('Fabio', null);

        $errors = $this->contributorErrors($submission);

        // With no family name, the contributor is known by the given name alone.
        $this->assertContains($this->message('familyName', 'Fabio'), $errors, implode(' | ', $errors));

        // And a contributor who has one is not named.
        $withName = $this->submissionAwaitingSubmit('Gabriela');
        $this->assertNotContains($this->message('familyName', 'Gabriela Contributor'), $this->contributorErrors($withName));
    }

    public function testNothingIsRequiredWhileTheJournalDidNotAskForIt(): void
    {
        $this->set('requireFamilyName', true);
        $this->set('requireAffiliation', true);
        $this->set('requireBiography', true);
        $this->set('requireOnSubmit', false);
        $submission = $this->submissionAwaitingSubmit('Carla', null);

        $errors = $this->contributorErrors($submission);
        $this->assertNotContains($this->message('affiliation', 'Carla'), $errors, 'the gate only closes where the journal closed it');
        $this->assertNotContains($this->message('biography', 'Carla'), $errors);
        $this->assertNotContains($this->message('familyName', 'Carla'), $errors);
    }

    public function testTheEditorKeepsTheAutonomyToCompleteIt(): void
    {
        $this->set('requireAffiliation', true);
        $this->set('requireOnSubmit', true);
        $this->set('editorsExempt', true);
        $submission = $this->submissionAwaitingSubmit('Dora');

        // Whoever is acting is read from the request.
        $manager = Repo::user()->getCollector()->filterByContextIds([self::CONTEXT_ID])->filterByRoleIds([Role::ROLE_ID_MANAGER])->limit(1)->getMany()->first();
        if (!$manager) {
            $this->markTestSkipped('this context has no journal manager to act as');
        }
        // Somebody who writes submissions and does not run the journal: many
        // accounts hold both roles, and those are exempt.
        $author = Repo::user()->getCollector()->filterByContextIds([self::CONTEXT_ID])->filterByRoleIds([Role::ROLE_ID_AUTHOR])->getMany()
            ->first(fn ($user) => !$user->hasRole(RequiredAuthorMetadataPlugin::EXEMPT_ROLES, self::CONTEXT_ID));

        $expected = $this->message('affiliation', 'Dora Contributor');
        if ($author) {
            Registry::set('user', $author);
            $this->assertContains($expected, $this->contributorErrors($submission), 'an author is held to the rule');
        }

        Registry::set('user', $manager);
        $this->assertNotContains($expected, $this->contributorErrors($submission), 'a journal manager completes the submission anyway');

        // Unless the journal took that autonomy away.
        $this->set('editorsExempt', false);
        $this->assertContains($expected, $this->contributorErrors($submission));
    }

    public function testItLivesBesideTheOrcidPluginInsteadOfReplacingIt(): void
    {
        $orcid = PluginRegistry::getPlugin('generic', 'orcidmanualentryplugin');
        if (!$orcid || !$orcid->getEnabled(self::CONTEXT_ID)) {
            $this->markTestSkipped('the orcidManualEntry plugin is not enabled here');
        }
        $wasRequired = $orcid->getSetting(self::CONTEXT_ID, 'requireOnSubmit');
        $orcid->updateSetting(self::CONTEXT_ID, 'requireOnSubmit', 1, 'bool');

        try {
            $this->set('requireAffiliation', true);
            $this->set('requireOnSubmit', true);
            $submission = $this->submissionAwaitingSubmit('Elena');

            $everything = implode(' | ', $this->contributorErrors($submission));

            // Both plugins write under the same key of the core: the author has
            // to see both reasons, not whichever ran last.
            $this->assertStringContainsString('ORCID', $everything, 'the iD is still asked for: ' . $everything);
            $this->assertStringContainsString(
                $this->message('affiliation', 'Elena Contributor'),
                $everything,
                'and so is the affiliation: ' . $everything
            );
        } finally {
            $orcid->updateSetting(self::CONTEXT_ID, 'requireOnSubmit', $wasRequired === null ? 0 : $wasRequired, 'bool');
        }
    }
}
