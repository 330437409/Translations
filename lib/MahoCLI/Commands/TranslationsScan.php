<?php

/**
 * Rebuilds the translation scan index.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'translations:scan',
    description: 'Scan source code and language packs for the admin translations manager',
)]
class TranslationsScan extends BaseMahoCommand
{
    public function __invoke(
        OutputInterface $output,
        #[Option(description: 'Show extra scan details')]
        bool $details = false,
    ): int {
        if (!$this->isModuleActive('Maho_Translations')) {
            $output->writeln('<error>The Maho_Translations module is not active.</error>');
            return Command::FAILURE;
        }

        $this->initMaho();

        try {
            $stats = Mage::getModel('translations/scanner')->scan();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Scanned %d files.', $stats['files_scanned'] ?? 0));
        $output->writeln(sprintf('Sources: %d', $stats['sources']));
        $output->writeln(sprintf('Locations: %d', $stats['locations']));
        $output->writeln(sprintf('Pack translations: %d', $stats['pack_translations']));
        $output->writeln(sprintf('Skipped dynamic strings: %d', $stats['skipped_dynamic'] ?? 0));
        $output->writeln(sprintf('Long strings: %d', $stats['long_strings'] ?? 0));

        if ($details) {
            $output->writeln(sprintf('Skipped files: %d', $stats['skipped_files'] ?? 0));
            $output->writeln(sprintf('Skipped XML files: %d', $stats['skipped_xml'] ?? 0));
            $output->writeln(sprintf('Pack conflicts: %d', $stats['pack_conflicts'] ?? 0));
            $output->writeln(sprintf('Duration: %.2f s', $stats['duration'] ?? 0));
        }

        return Command::SUCCESS;
    }
}
