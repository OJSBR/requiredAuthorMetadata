<?php

/**
 * @file plugins/generic/requiredAuthorMetadata/RequiredAuthorMetadataSettingsForm.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RequiredAuthorMetadataSettingsForm
 *
 * @brief What the journal requires of its contributors, and who is left out of it.
 */

namespace APP\plugins\generic\requiredAuthorMetadata;

use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class RequiredAuthorMetadataSettingsForm extends Form
{
    public function __construct(public RequiredAuthorMetadataPlugin $plugin, public Context $context)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        foreach (array_keys(RequiredAuthorMetadataPlugin::DEFAULTS) as $name) {
            $this->setData($name, $this->plugin->getFlag($this->context->getId(), $name));
        }

        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(array_keys(RequiredAuthorMetadataPlugin::DEFAULTS));

        parent::readInputData();
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        TemplateManager::getManager($request)->assign('pluginName', $this->plugin->getName());

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        foreach (array_keys(RequiredAuthorMetadataPlugin::DEFAULTS) as $name) {
            $this->plugin->updateSetting($this->context->getId(), $name, $this->getData($name) ? 1 : 0, 'bool');
        }

        return parent::execute(...$functionArgs);
    }
}
