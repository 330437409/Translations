<?php

/**
 * Translation source index resource.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Resource_Source extends Mage_Core_Model_Resource_Db_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('translations/source', 'source_id');
    }
}
