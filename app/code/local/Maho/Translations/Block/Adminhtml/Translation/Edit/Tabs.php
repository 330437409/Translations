<?php

/**
 * Translation edit tabs.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Block_Adminhtml_Translation_Edit_Tabs extends Mage_Adminhtml_Block_Widget_Tabs
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('translations_edit_tabs');
        $this->setDestElementId('edit_form');
        $this->setTitle(Mage::helper('translations')->__('Translation Information'));
    }
}
