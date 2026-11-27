<?php

/**
 * Admin translation manager controller.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Adminhtml_TranslationsController extends Mage_Adminhtml_Controller_Action
{
    public const string ADMIN_RESOURCE = 'system/translations';

    private const array ACTION_RESOURCES = [
        'index' => 'system/translations',
        'grid' => 'system/translations',
        'edit' => 'system/translations',
        'save' => 'system/translations/save',
        'delete' => 'system/translations/save',
        'scan' => 'system/translations/scan',
        'import' => 'system/translations/import',
        'export' => 'system/translations/export',
        'aigenerate' => 'system/translations/ai',
        'aidraftsave' => 'system/translations/save',
        'aiapprove' => 'system/translations/save',
        'aireject' => 'system/translations/save',
    ];

    #[\Override]
    protected function _isAllowed(): bool
    {
        $action = strtolower((string) $this->getRequest()->getActionName());
        $resource = self::ACTION_RESOURCES[$action] ?? self::ADMIN_RESOURCE;
        return Mage::getSingleton('admin/session')->isAllowed($resource);
    }

    #[Maho\Config\Route('/admin/translations/index', methods: ['GET'])]
    public function indexAction(): void
    {
        $this->_initAction();
        $this->_title(Mage::helper('translations')->__('Translations'));
        $this->renderLayout();
    }

    #[Maho\Config\Route('/admin/translations/grid', methods: ['GET'])]
    public function gridAction(): void
    {
        $grid = $this->getLayout()->createBlock('translations/adminhtml_translation_grid');
        $this->getResponse()->setBody($grid->toHtml());
    }

    #[Maho\Config\Route('/admin/translations/edit', methods: ['GET'])]
    public function editAction(): void
    {
        $sourceId = (int) $this->getRequest()->getParam('source_id', 0);
        $source = Mage::getModel('translations/catalog')->getSource($sourceId);

        if (!$source) {
            Mage::getSingleton('adminhtml/session')->addError(
                Mage::helper('translations')->__('The translation source was not found. Run a scan and try again.'),
            );
            $this->_redirect('*/*/index');
            return;
        }

        Mage::register('translations_source', $source);
        $this->_initAction();
        $this->_title(Mage::helper('translations')->__('Edit Translation'));
        $this->renderLayout();
    }

    #[Maho\Config\Route('/admin/translations/save', methods: ['POST'])]
    public function saveAction(): void
    {
        $sourceId = (int) $this->getRequest()->getParam('source_id', 0);
        $locale = (string) $this->getRequest()->getParam('locale', 'en_US');
        $storeId = (int) $this->getRequest()->getParam('store', 0);
        $translation = $this->getRequest()->getParam('translation');

        $catalog = Mage::getModel('translations/catalog');
        $source = $catalog->getSource($sourceId);
        $helper = Mage::helper('translations');
        $session = Mage::getSingleton('adminhtml/session');

        if (!$source) {
            $session->addError($helper->__('The translation source was not found.'));
            $this->_redirect('*/*/index');
            return;
        }

        if (!in_array($locale, $catalog->getLocales(), true)) {
            $session->addError($helper->__('The selected locale is not valid.'));
            $this->_redirect('*/*/edit', $this->scopeParams($sourceId, $locale, $storeId));
            return;
        }

        if (!array_key_exists($storeId, $catalog->getStoreOptions())) {
            $session->addError($helper->__('The selected store is not valid.'));
            $this->_redirect('*/*/edit', $this->scopeParams($sourceId, $locale, $storeId));
            return;
        }

        if (!is_string($translation) && $translation !== null) {
            $session->addError($helper->__('The translation value is not valid.'));
            $this->_redirect('*/*/edit', $this->scopeParams($sourceId, $locale, $storeId));
            return;
        }

        if ($translation !== null && trim($translation) !== '') {
            $validator = new Maho_Translations_Model_Validator_Placeholder();
            $errors = $validator->validate((string) $source['source_text'], $translation);
            if ($errors !== []) {
                $this->rememberTranslationDraft($sourceId, $locale, $storeId, $translation);
                foreach ($errors as $error) {
                    $session->addError($error);
                }
                $this->_redirect('*/*/edit', $this->scopeParams($sourceId, $locale, $storeId));
                return;
            }
        }

        try {
            $catalog->saveTranslation($source, $translation, $locale, $storeId);
            $session->unsetData('translations_form_draft');
            $session->addSuccess($helper->__('The translation was saved.'));
        } catch (Throwable $e) {
            Mage::logException($e);
            $this->rememberTranslationDraft($sourceId, $locale, $storeId, $translation);
            $session->addError($helper->__('The translation could not be saved: %s', $e->getMessage()));
        }

        $this->_redirect('*/*/index', ['locale' => $locale, 'store' => $storeId]);
    }

    #[Maho\Config\Route('/admin/translations/delete', methods: ['POST'])]
    public function deleteAction(): void
    {
        $sourceId = (int) $this->getRequest()->getParam('source_id', 0);
        $locale = (string) $this->getRequest()->getParam('locale', 'en_US');
        $storeId = (int) $this->getRequest()->getParam('store', 0);
        $catalog = Mage::getModel('translations/catalog');
        $source = $catalog->getSource($sourceId);
        $session = Mage::getSingleton('adminhtml/session');

        if (!$source) {
            $session->addError(Mage::helper('translations')->__('The translation source was not found.'));
            $this->_redirect('*/*/index');
            return;
        }

        try {
            $catalog->clearTranslation((string) $source['source_text'], $locale, $storeId);
            $session->addSuccess(Mage::helper('translations')->__('The translation override was cleared.'));
        } catch (Throwable $e) {
            Mage::logException($e);
            $session->addError(Mage::helper('translations')->__('The override could not be cleared: %s', $e->getMessage()));
        }

        $this->_redirect('*/*/index', ['locale' => $locale, 'store' => $storeId]);
    }

    #[Maho\Config\Route('/admin/translations/scan', methods: ['POST'])]
    public function scanAction(): void
    {
        $session = Mage::getSingleton('adminhtml/session');
        $helper = Mage::helper('translations');
        $locale = (string) $this->getRequest()->getParam('locale', 'en_US');
        $storeId = (int) $this->getRequest()->getParam('store', 0);

        try {
            $stats = Mage::getModel('translations/scanner')->scan();
            $session->addSuccess($helper->__(
                'Scan complete: %s sources, %s locations, %s language pack translations.',
                $stats['sources'],
                $stats['locations'],
                $stats['pack_translations'],
            ));
            if (($stats['skipped_dynamic'] ?? 0) > 0) {
                $session->addNotice($helper->__(
                    '%s dynamic translation strings were skipped.',
                    $stats['skipped_dynamic'],
                ));
            }
        } catch (Throwable $e) {
            Mage::logException($e);
            $session->addError($helper->__('The scan failed: %s', $e->getMessage()));
        }

        $params = ['locale' => $locale, 'store' => $storeId];
        foreach (['status', 'module', 'area', 'q'] as $name) {
            $value = (string) $this->getRequest()->getParam($name, '');
            if ($value !== '') {
                $params[$name] = $value;
            }
        }
        $this->_redirect('*/*/index', $params);
    }

    #[Maho\Config\Route('/admin/translations/import')]
    public function importAction(): void
    {
        if ($this->getRequest()->isPost()) {
            $this->handleImport();
            return;
        }

        $this->_initAction();
        $this->_title(Mage::helper('translations')->__('Import Translations'));
        $this->renderLayout();
    }

    #[Maho\Config\Route('/admin/translations/export', methods: ['GET'])]
    public function exportAction(): void
    {
        if (!$this->getRequest()->getParam('download')) {
            $this->_initAction();
            $this->_title(Mage::helper('translations')->__('Export Translations'));
            $this->renderLayout();
            return;
        }

        $locale = (string) $this->getRequest()->getParam('locale', 'en_US');
        $storeId = (int) $this->getRequest()->getParam('store', 0);
        $scope = (string) $this->getRequest()->getParam('scope', 'effective');
        if (!in_array($scope, ['effective', 'missing', 'overrides'], true)) {
            $scope = 'effective';
        }

        $rows = Mage::getModel('translations/transfer')->export($scope, $locale, $storeId);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            $this->getResponse()->setHttpResponseCode(500);
            return;
        }
        $csv = new Maho_Translations_Model_Csv();
        foreach ($rows as $row) {
            $csv->writeRow($stream, $row['source'], $row['translation']);
        }
        rewind($stream);
        $body = (string) stream_get_contents($stream);
        fclose($stream);

        $filename = sprintf(
            'translations_%s_store%d_%s_%s.csv',
            preg_replace('/[^A-Za-z0-9_\-]/', '_', $locale),
            $storeId,
            $scope,
            date('Ymd_His'),
        );

        $this->getResponse()
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8', true)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"', true)
            ->setBody($body);
    }

    #[Maho\Config\Route('/admin/translations/aiGenerate', methods: ['POST'])]
    public function aiGenerateAction(): void
    {
        $session = Mage::getSingleton('adminhtml/session');
        $helper = Mage::helper('translations');
        $locale = (string) $this->getRequest()->getParam('locale', 'en_US');
        $storeId = (int) $this->getRequest()->getParam('store', 0);
        $mode = (string) $this->getRequest()->getParam('mode', 'selected');
        $sourceIds = $this->getRequest()->getParam('source_ids', []);
        if (is_string($sourceIds)) {
            $sourceIds = explode(',', $sourceIds);
        }
        if (!is_array($sourceIds)) {
            $sourceIds = [];
        }

        try {
            $result = $this->aiService()->requestGeneration(
                $sourceIds,
                $mode === 'missing',
                $locale,
                $storeId,
                $this->aiFilters(),
                $this->adminUserId(),
            );

            if ($result['queued'] > 0) {
                $session->addSuccess($helper->__('%s AI drafts were queued.', $result['queued']));
            }
            if ($result['generated'] > 0) {
                $session->addSuccess($helper->__('%s AI drafts were generated.', $result['generated']));
            }
            if ($result['skipped'] > 0) {
                $session->addNotice($helper->__('%s translations were skipped.', $result['skipped']));
            }
            if ($result['failed'] > 0) {
                $session->addError($helper->__('%s AI drafts failed.', $result['failed']));
                $this->addErrors($result['errors']);
            }
        } catch (Throwable $e) {
            Mage::logException($e);
            $session->addError($helper->__('The AI drafts could not be generated: %s', $e->getMessage()));
        }

        $this->_redirect('*/*/index', ['locale' => $locale, 'store' => $storeId]);
    }

    #[Maho\Config\Route('/admin/translations/aiDraftSave', methods: ['POST'])]
    public function aiDraftSaveAction(): void
    {
        $session = Mage::getSingleton('adminhtml/session');
        $helper = Mage::helper('translations');
        $draftId = (int) $this->getRequest()->getParam('draft_id', 0);
        $translation = $this->getRequest()->getParam('ai_draft_translation');
        $redirect = $this->aiDraftScopeParams();

        if (!is_string($translation) && $translation !== null) {
            $session->addError($helper->__('The AI draft value is not valid.'));
            $this->_redirect('*/*/edit', $redirect);
            return;
        }

        try {
            $this->aiService()->saveDraft($draftId, $translation);
            $session->addSuccess($helper->__('The AI draft was saved.'));
        } catch (Throwable $e) {
            Mage::logException($e);
            $session->addError($helper->__('The AI draft could not be saved: %s', $e->getMessage()));
        }

        $this->_redirect('*/*/edit', $redirect);
    }

    #[Maho\Config\Route('/admin/translations/aiApprove', methods: ['POST'])]
    public function aiApproveAction(): void
    {
        $this->handleAiReview(true);
    }

    #[Maho\Config\Route('/admin/translations/aiReject', methods: ['POST'])]
    public function aiRejectAction(): void
    {
        $this->handleAiReview(false);
    }

    protected function _initAction(): static
    {
        $this->loadLayout()
            ->_setActiveMenu('system/translations')
            ->_addBreadcrumb(
                Mage::helper('translations')->__('Translations'),
                Mage::helper('translations')->__('Translations'),
            );
        return $this;
    }

    private function handleImport(): void
    {
        $session = Mage::getSingleton('adminhtml/session');
        $helper = Mage::helper('translations');
        $locale = (string) $this->getRequest()->getParam('locale', 'en_US');
        $storeId = (int) $this->getRequest()->getParam('store', 0);
        $redirectParams = ['locale' => $locale, 'store' => $storeId];
        $catalog = Mage::getModel('translations/catalog');

        if (!in_array($locale, $catalog->getLocales(), true)) {
            $session->addError($helper->__('The selected locale is not valid.'));
            $this->_redirect('*/*/import', $redirectParams);
            return;
        }

        if (!array_key_exists($storeId, $catalog->getStoreOptions())) {
            $session->addError($helper->__('The selected store is not valid.'));
            $this->_redirect('*/*/import', $redirectParams);
            return;
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $session->addError($helper->__('Upload a CSV file.'));
            $this->_redirect('*/*/import', $redirectParams);
            return;
        }

        if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
            $session->addError($helper->__('The CSV file is larger than 10 MB.'));
            $this->_redirect('*/*/import', $redirectParams);
            return;
        }

        if (strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $session->addError($helper->__('The uploaded file must have a .csv extension.'));
            $this->_redirect('*/*/import', $redirectParams);
            return;
        }

        $tmpName = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmpName)) {
            $session->addError($helper->__('The uploaded file could not be read.'));
            $this->_redirect('*/*/import', $redirectParams);
            return;
        }

        $actualSize = filesize($tmpName);
        if ($actualSize === false || $actualSize > 10 * 1024 * 1024) {
            $session->addError($helper->__('The CSV file is larger than 10 MB.'));
            $this->_redirect('*/*/import', $redirectParams);
            return;
        }

        $mimeType = new finfo(FILEINFO_MIME_TYPE)->file($tmpName);
        if (!in_array($mimeType, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true)) {
            $session->addError($helper->__('The uploaded file is not a CSV file.'));
            $this->_redirect('*/*/import', $redirectParams);
            return;
        }

        $parsed = Mage::getModel('translations/csv')->parse($tmpName);
        if ($parsed['errors'] !== []) {
            $this->addErrors($parsed['errors']);
            $this->_redirect('*/*/import', $redirectParams);
            return;
        }

        try {
            $result = Mage::getModel('translations/transfer')->import($parsed['rows'], $locale, $storeId);
        } catch (Throwable $e) {
            Mage::logException($e);
            $session->addError($helper->__('The import failed: %s', $e->getMessage()));
            $this->_redirect('*/*/import', $redirectParams);
            return;
        }

        if ($result['errors'] !== []) {
            $this->addErrors($result['errors']);
            $this->_redirect('*/*/import', $redirectParams);
            return;
        }

        $session->addSuccess($helper->__(
            'Import complete: %s created, %s updated, %s cleared, %s unchanged.',
            $result['created'],
            $result['updated'],
            $result['cleared'],
            $result['unchanged'],
        ));
        $this->_redirect('*/*/index', $redirectParams);
    }

    /**
     * @param array<int, string> $errors
     */
    private function addErrors(array $errors): void
    {
        $session = Mage::getSingleton('adminhtml/session');
        foreach (array_slice($errors, 0, 20) as $error) {
            $session->addError($error);
        }
        if (count($errors) > 20) {
            $session->addError(Mage::helper('translations')->__('And %s more errors.', count($errors) - 20));
        }
    }

    private function handleAiReview(bool $approve): void
    {
        $session = Mage::getSingleton('adminhtml/session');
        $helper = Mage::helper('translations');
        $locale = (string) $this->getRequest()->getParam('locale', 'en_US');
        $storeId = (int) $this->getRequest()->getParam('store', 0);
        $sourceIds = $this->getRequest()->getParam('source_ids', []);
        if (is_string($sourceIds)) {
            $sourceIds = explode(',', $sourceIds);
        }
        if (!is_array($sourceIds)) {
            $sourceIds = [];
        }
        $draftId = (int) $this->getRequest()->getParam('draft_id', 0);
        try {
            if ($draftId > 0) {
                if ($approve) {
                    $translation = $this->getRequest()->getParam('ai_draft_translation');
                    if (is_string($translation)) {
                        $this->aiService()->saveDraft($draftId, $translation);
                    }
                    $this->aiService()->approveDraft($draftId, $this->adminUserId());
                    $session->addSuccess($helper->__('The AI draft was approved.'));
                } else {
                    $this->aiService()->rejectDraft($draftId, $this->adminUserId());
                    $session->addSuccess($helper->__('The AI draft was rejected.'));
                }
                $this->_redirect('*/*/edit', $this->aiDraftScopeParams());
                return;
            }

            if ($sourceIds === []) {
                throw new Mage_Core_Exception($helper->__('Select at least one AI draft.'));
            }

            $result = $approve
                ? $this->aiService()->approveBySourceIds($sourceIds, $locale, $storeId, $this->adminUserId())
                : $this->aiService()->rejectBySourceIds($sourceIds, $locale, $storeId, $this->adminUserId());

            if ($result['succeeded'] > 0) {
                $session->addSuccess($approve
                    ? $helper->__('%s AI drafts were approved.', $result['succeeded'])
                    : $helper->__('%s AI drafts were rejected.', $result['succeeded']));
            }
            if ($result['failed'] > 0) {
                $session->addError($approve
                    ? $helper->__('%s AI drafts could not be approved.', $result['failed'])
                    : $helper->__('%s AI drafts could not be rejected.', $result['failed']));
                $this->addErrors($result['errors']);
            }
        } catch (Throwable $e) {
            Mage::logException($e);
            $session->addError($helper->__('The AI drafts could not be reviewed: %s', $e->getMessage()));
        }

        $this->_redirect('*/*/index', ['locale' => $locale, 'store' => $storeId]);
    }

    private function aiService(): Maho_Translations_Service_AiDraft
    {
        return Mage::getService('translations/aiDraft');
    }

    /**
     * @return array{module: string, area: string, q: string}
     */
    private function aiFilters(): array
    {
        return [
            'module' => (string) $this->getRequest()->getParam('module', ''),
            'area' => (string) $this->getRequest()->getParam('area', ''),
            'q' => (string) $this->getRequest()->getParam('q', ''),
        ];
    }

    private function adminUserId(): int
    {
        $user = Mage::getSingleton('admin/session')->getUser();
        return $user ? (int) $user->getId() : 0;
    }

    /**
     * @return array<string, int|string>
     */
    private function aiDraftScopeParams(): array
    {
        return [
            'source_id' => (int) $this->getRequest()->getParam('source_id', 0),
            'locale' => (string) $this->getRequest()->getParam('locale', 'en_US'),
            'store' => (int) $this->getRequest()->getParam('store', 0),
            'active_tab' => 'ai_draft',
        ];
    }

    private function rememberTranslationDraft(int $sourceId, string $locale, int $storeId, mixed $translation): void
    {
        if (!is_string($translation)) {
            return;
        }

        Mage::getSingleton('adminhtml/session')->setData('translations_form_draft', [
            'source_id' => $sourceId,
            'locale' => $locale,
            'store' => $storeId,
            'translation' => $translation,
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function scopeParams(int $sourceId, string $locale, int $storeId): array
    {
        return [
            'source_id' => $sourceId,
            'locale' => $locale,
            'store' => $storeId,
        ];
    }
}
