<?php

/**
 * @file plugins/generic/requiredAuthorMetadata/tests/RequiredFieldsTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RequiredFieldsTest
 *
 * @brief What counts as a contributor without an affiliation or without a
 *        biography, the settings that decide it, and who is left out of the
 *        rules.
 */

namespace APP\plugins\generic\requiredAuthorMetadata\tests;

use APP\core\Application;
use APP\core\PageRouter;
use APP\plugins\generic\requiredAuthorMetadata\RequiredAuthorMetadataPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\affiliation\Affiliation;
use PKP\security\Role;
use PKP\tests\PKPTestCase;

#[CoversClass(RequiredAuthorMetadataPlugin::class)]
class RequiredFieldsTest extends PKPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $request = Application::get()->getRequest();
        if (!$request->getRouter()) {
            $router = new PageRouter();
            $router->setApplication(Application::get());
            $request->setRouter($router);
        }
    }

    /** A plugin with the given settings, which never reaches the database. */
    protected function plugin(array $settings = []): RequiredAuthorMetadataPlugin
    {
        return new class ($settings) extends RequiredAuthorMetadataPlugin {
            public function __construct(private array $settings)
            {
                parent::__construct();
            }

            public function getFlag(?int $contextId, string $name): bool
            {
                return array_key_exists($name, $this->settings)
                    ? (bool) $this->settings[$name]
                    : (self::DEFAULTS[$name] ?? false);
            }
        };
    }

    /** An affiliation as the API sends it. */
    protected function affiliation(?string $ror, array $name): array
    {
        return ['ror' => $ror, 'name' => $name];
    }

    public function testASettingThatWasNeverSavedFallsBackToItsDefault(): void
    {
        $defaults = RequiredAuthorMetadataPlugin::DEFAULTS;

        // A journal that enabled the plugin and chose nothing requires nothing —
        // and whoever runs the journal is left out of it from the start.
        $this->assertFalse($defaults['requireAffiliation']);
        $this->assertFalse($defaults['requireBiography']);
        $this->assertFalse($defaults['requireOnSubmit']);
        $this->assertTrue($defaults['editorsExempt']);

        $plugin = $this->plugin(['requireAffiliation' => true]);
        $this->assertTrue($plugin->getFlag(1, 'requireAffiliation'));
        $this->assertFalse($plugin->getFlag(1, 'requireBiography'), 'a setting left alone keeps its default');
        $this->assertFalse($plugin->getFlag(1, 'nonsense'), 'an unknown setting is never true');
    }

    public function testAContributorWithoutAnAffiliationIsRecognized(): void
    {
        $missing = RequiredAuthorMetadataPlugin::affiliationsMissing(...);

        $this->assertTrue($missing([]), 'no affiliation at all');
        $this->assertTrue($missing([$this->affiliation(null, [])]), 'an entry with nothing in it');
        $this->assertTrue($missing([$this->affiliation('', ['en' => '  '])]), 'a name of spaces is not a name');

        $this->assertFalse($missing([$this->affiliation(null, ['en' => 'Universidade Federal'])]), 'a typed name is an affiliation');
        $this->assertFalse($missing([$this->affiliation('https://ror.org/04wffgt70', [])]), 'an organization identifier alone is an affiliation');
        $this->assertFalse(
            $missing([$this->affiliation(null, []), $this->affiliation(null, ['pt_BR' => 'Instituto'])]),
            'one filled entry is enough, whatever the others are'
        );
    }

    public function testTheSameIsRecognizedOnTheStoredAffiliationObjects(): void
    {
        // What the plugin reads when the save carries no affiliation at all.
        $empty = new Affiliation();
        $this->assertTrue(RequiredAuthorMetadataPlugin::affiliationsMissing([$empty]));

        $named = new Affiliation();
        $named->setName('Universidade Federal', 'pt_BR');
        $this->assertFalse(RequiredAuthorMetadataPlugin::affiliationsMissing([$named]));

        $identified = new Affiliation();
        $identified->setRor('https://ror.org/04wffgt70');
        $this->assertFalse(RequiredAuthorMetadataPlugin::affiliationsMissing([$identified]));
    }

    public function testAnEmptyBiographyIsRecognizedEvenWhenTheEditorLeftMarkupBehind(): void
    {
        $missing = RequiredAuthorMetadataPlugin::biographyMissing(...);

        $this->assertTrue($missing(null, 'en'));
        $this->assertTrue($missing([], 'en'));
        $this->assertTrue($missing(['en' => ''], 'en'));
        $this->assertTrue($missing(['en' => '   '], 'en'));
        $this->assertTrue($missing(['en' => '<p></p>'], 'en'), 'an empty paragraph is empty');
        $this->assertTrue($missing(['en' => '<p>&nbsp;</p>'], 'en'), 'what a rich text editor leaves behind is empty');

        $this->assertFalse($missing(['en' => '<p>Researcher.</p>'], 'en'));
        // The language of the submission is what counts, and no other.
        $this->assertTrue($missing(['pt_BR' => '<p>Pesquisadora.</p>'], 'en'));
        $this->assertFalse($missing(['pt_BR' => '<p>Pesquisadora.</p>'], 'pt_BR'));
    }

    public function testWhoIsLeftOutOfTheRules(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/RequiredAuthorMetadataPlugin.php');

        // Only the roles that decide about the submission; an assistant follows
        // the rule like everybody else.
        $this->assertSame(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
            RequiredAuthorMetadataPlugin::EXEMPT_ROLES
        );
        $this->assertNotContains(Role::ROLE_ID_ASSISTANT, RequiredAuthorMetadataPlugin::EXEMPT_ROLES);

        // Nobody is exempt where the journal turned the exemption off, whoever
        // they are.
        $this->assertFalse($this->plugin(['editorsExempt' => false])->isExempt(1));
        // And there is nobody to exempt outside a journal.
        $this->assertFalse($this->plugin(['editorsExempt' => true])->isExempt(null));

        // The exemption is read once and applied to all three rules.
        $this->assertStringContainsString('$this->isExempt($contextId)', $source);
        $this->assertSame(3, substr_count($source, '$this->isExempt('), 'each of the three rules has to honour it');
    }

    public function testTheRulesAreHungOnTheHooksOfTheCore(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/RequiredAuthorMetadataPlugin.php');

        // The form shows it, the endpoint refuses it, the wizard is stopped by it.
        $this->assertStringContainsString("Hook::add('Form::config::before'", $source);
        $this->assertStringContainsString("Hook::add('Author::validate'", $source);
        $this->assertStringContainsString("Hook::add('Submission::validateSubmit'", $source);

        // The message of the last step goes under the key the core uses for its
        // own contributor errors, and is appended so that it lives beside what
        // another plugin may have held against the same submission.
        $this->assertStringContainsString("\$errors['contributors'] ??= [];", $source);
        $this->assertStringContainsString("\$errors['contributors'][] = __(", $source);
        $this->assertStringNotContainsString("\$errors['contributors'] = [", $source);
    }
}
