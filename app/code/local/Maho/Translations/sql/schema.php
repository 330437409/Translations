<?php

/**
 * Translation scan index tables.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\SchemaEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Maho\Db\Schema;

return function (SchemaEditor $schema): void {
    $schema->addTable(
        Table::editor()
            ->setUnquotedName('translation_source')
            ->addColumn(Schema::column('source_id', Types::INTEGER, unsigned: true, autoincrement: true))
            ->addColumn(Schema::column('source_hash', Types::STRING, length: 32))
            ->addColumn(Schema::column('source_crc', Types::BIGINT))
            ->addColumn(Schema::column('source_text', Types::TEXT, length: 65535))
            ->addColumn(Schema::column('source_length', Types::INTEGER, unsigned: true, default: 0))
            ->addColumn(Schema::column('is_used', Types::SMALLINT, unsigned: true, default: 0))
            ->addColumn(Schema::column('is_supported', Types::SMALLINT, unsigned: true, default: 1))
            ->addColumn(Schema::column('modules', Types::TEXT, length: 65535))
            ->addColumn(Schema::column('areas', Types::STRING, length: 128, default: ''))
            ->addColumn(Schema::column('locations_count', Types::INTEGER, unsigned: true, default: 0))
            ->addColumn(Schema::column('first_seen_at', Types::DATETIME_IMMUTABLE, default: new CurrentTimestamp()))
            ->addColumn(Schema::column('last_seen_at', Types::DATETIME_IMMUTABLE, default: new CurrentTimestamp()))
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('source_id')->create())
            ->addIndex(
                Index::editor()
                    ->setType(IndexType::UNIQUE)
                    ->setUnquotedColumnNames('source_hash'),
            )
            ->addIndex(Index::editor()->setUnquotedColumnNames('source_crc'))
            ->addIndex(Index::editor()->setUnquotedColumnNames('is_used'))
            ->addIndex(Index::editor()->setUnquotedColumnNames('is_supported'))
            ->setComment('Translation Source Strings')
            ->create(),
    );

    $schema->addTable(
        Table::editor()
            ->setUnquotedName('translation_location')
            ->addColumn(Schema::column('location_id', Types::INTEGER, unsigned: true, autoincrement: true))
            ->addColumn(Schema::column('source_id', Types::INTEGER, unsigned: true))
            ->addColumn(Schema::column('module', Types::STRING, length: 128, default: ''))
            ->addColumn(Schema::column('area', Types::STRING, length: 16, default: ''))
            ->addColumn(Schema::column('file', Types::STRING, length: 255, default: ''))
            ->addColumn(Schema::column('line', Types::INTEGER, unsigned: true, notNull: false))
            ->addColumn(Schema::column('path', Types::STRING, length: 255, notNull: false))
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('location_id')->create())
            ->addIndex(
                Index::editor()
                    ->setType(IndexType::UNIQUE)
                    ->setUnquotedColumnNames('source_id', 'module', 'area', 'file', 'line'),
            )
            ->addIndex(Index::editor()->setUnquotedColumnNames('source_id'))
            ->addIndex(Index::editor()->setUnquotedColumnNames('module'))
            ->addIndex(Index::editor()->setUnquotedColumnNames('area'))
            ->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedReferencingColumnNames('source_id')
                    ->setUnquotedReferencedTableName('translation_source')
                    ->setUnquotedReferencedColumnNames('source_id')
                    ->setOnDeleteAction(ReferentialAction::CASCADE)
                    ->create(),
            )
            ->setComment('Translation Source Locations')
            ->create(),
    );

    $schema->addTable(
        Table::editor()
            ->setUnquotedName('translation_pack')
            ->addColumn(Schema::column('pack_id', Types::INTEGER, unsigned: true, autoincrement: true))
            ->addColumn(Schema::column('locale', Types::STRING, length: 20))
            ->addColumn(Schema::column('source_hash', Types::STRING, length: 32))
            ->addColumn(Schema::column('source_text', Types::TEXT, length: 65535))
            ->addColumn(Schema::column('translation', Types::TEXT, length: 65535, notNull: false))
            ->addColumn(Schema::column('module', Types::STRING, length: 128, default: ''))
            ->addColumn(Schema::column('file', Types::STRING, length: 255, default: ''))
            ->addColumn(Schema::column('conflict_count', Types::INTEGER, unsigned: true, default: 0))
            ->addColumn(Schema::column('alternatives', Types::TEXT, length: 65535, notNull: false))
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('pack_id')->create())
            ->addIndex(
                Index::editor()
                    ->setType(IndexType::UNIQUE)
                    ->setUnquotedColumnNames('locale', 'source_hash'),
            )
            ->addIndex(Index::editor()->setUnquotedColumnNames('locale'))
            ->setComment('Translation Language Pack Snapshot')
            ->create(),
    );

    $schema->addTable(
        Table::editor()
            ->setUnquotedName('translation_long')
            ->addColumn(Schema::column('long_id', Types::INTEGER, unsigned: true, autoincrement: true))
            ->addColumn(Schema::column('source_hash', Types::STRING, length: 32))
            ->addColumn(Schema::column('source_text', Types::TEXT, length: 65535))
            ->addColumn(Schema::column('translation', Types::TEXT, length: 65535))
            ->addColumn(Schema::column('locale', Types::STRING, length: 20))
            ->addColumn(Schema::column('store_id', Types::SMALLINT, unsigned: true, default: 0))
            ->addColumn(Schema::column('created_at', Types::DATETIME_IMMUTABLE, default: new CurrentTimestamp()))
            ->addColumn(Schema::column('updated_at', Types::DATETIME_IMMUTABLE, default: new CurrentTimestamp()))
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('long_id')->create())
            ->addIndex(
                Index::editor()
                    ->setType(IndexType::UNIQUE)
                    ->setUnquotedColumnNames('source_hash', 'locale', 'store_id'),
            )
            ->addIndex(Index::editor()->setUnquotedColumnNames('locale', 'store_id'))
            ->setComment('Long Translation Overrides')
            ->create(),
    );

    $schema->addTable(
        Table::editor()
            ->setUnquotedName('translation_draft')
            ->addColumn(Schema::column('draft_id', Types::INTEGER, unsigned: true, autoincrement: true))
            ->addColumn(Schema::column('task_id', Types::INTEGER, unsigned: true, notNull: false))
            ->addColumn(Schema::column('source_hash', Types::STRING, length: 32))
            ->addColumn(Schema::column('source_text', Types::TEXT, length: 65535))
            ->addColumn(Schema::column('locale', Types::STRING, length: 20))
            ->addColumn(Schema::column('store_id', Types::SMALLINT, unsigned: true, default: 0))
            ->addColumn(Schema::column('base_translation', Types::TEXT, length: 65535, notNull: false))
            ->addColumn(Schema::column('draft_translation', Types::TEXT, length: 65535, notNull: false))
            ->addColumn(Schema::column('status', Types::STRING, length: 16, default: 'pending'))
            ->addColumn(Schema::column('platform', Types::STRING, length: 50, notNull: false))
            ->addColumn(Schema::column('model', Types::STRING, length: 100, notNull: false))
            ->addColumn(Schema::column('error_message', Types::TEXT, length: 65535, notNull: false))
            ->addColumn(Schema::column('requested_by', Types::INTEGER, unsigned: true, notNull: false))
            ->addColumn(Schema::column('reviewed_by', Types::INTEGER, unsigned: true, notNull: false))
            ->addColumn(Schema::column('created_at', Types::DATETIME_IMMUTABLE, default: new CurrentTimestamp()))
            ->addColumn(Schema::column('updated_at', Types::DATETIME_IMMUTABLE, default: new CurrentTimestamp()))
            ->addColumn(Schema::column('reviewed_at', Types::DATETIME_IMMUTABLE, notNull: false))
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('draft_id')->create())
            ->addIndex(
                Index::editor()
                    ->setType(IndexType::UNIQUE)
                    ->setUnquotedColumnNames('source_hash', 'locale', 'store_id'),
            )
            ->addIndex(Index::editor()->setUnquotedColumnNames('status'))
            ->addIndex(Index::editor()->setUnquotedColumnNames('locale', 'store_id', 'status'))
            ->addIndex(Index::editor()->setUnquotedColumnNames('task_id'))
            ->setComment('AI Translation Drafts')
            ->create(),
    );

    $schema->addTable(
        Table::editor()
            ->setUnquotedName('translation_scan')
            ->addColumn(Schema::column('scan_id', Types::INTEGER, unsigned: true, autoincrement: true))
            ->addColumn(Schema::column('status', Types::STRING, length: 16, default: 'running'))
            ->addColumn(Schema::column('started_at', Types::DATETIME_IMMUTABLE, default: new CurrentTimestamp()))
            ->addColumn(Schema::column('finished_at', Types::DATETIME_IMMUTABLE, notNull: false))
            ->addColumn(Schema::column('source_count', Types::INTEGER, unsigned: true, default: 0))
            ->addColumn(Schema::column('location_count', Types::INTEGER, unsigned: true, default: 0))
            ->addColumn(Schema::column('pack_count', Types::INTEGER, unsigned: true, default: 0))
            ->addColumn(Schema::column('skipped_count', Types::INTEGER, unsigned: true, default: 0))
            ->addColumn(Schema::column('message', Types::TEXT, length: 65535, notNull: false))
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('scan_id')->create())
            ->addIndex(Index::editor()->setUnquotedColumnNames('status', 'started_at'))
            ->setComment('Translation Scan Runs')
            ->create(),
    );
};
