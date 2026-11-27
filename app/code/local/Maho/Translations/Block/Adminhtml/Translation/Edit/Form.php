<?php

/**
 * Translation edit form container.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Block_Adminhtml_Translation_Edit_Form extends Mage_Adminhtml_Block_Widget_Form
{
    #[\Override]
    protected function _prepareForm()
    {
        $form = new \Maho\Data\Form();
        $form->setAction($this->getUrl('*/*/save', ['_nosecret' => true]));
        $form->setMethod('post');
        $form->setId('edit_form');
        $form->setUseContainer(true);

        $this->setForm($form);
        return parent::_prepareForm();
    }
}
