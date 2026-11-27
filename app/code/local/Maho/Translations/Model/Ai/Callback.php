<?php

/**
 * Receives completed AI translation tasks.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Ai_Callback implements Maho_Ai_Model_TaskCallbackInterface
{
    public function onComplete(Maho_Ai_Model_Task $task, string $response): void
    {
        Mage::getService('translations/aiDraft')->applyTaskResponse($task, $response);
    }
}
