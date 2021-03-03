<?php

/**
 * Pimcore
 *
 * This source file is available under following license:
 * - Pimcore Enterprise License (PEL)
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     PEL
 */

namespace Pimcore\Bundle\DataImporterBundle\DataSource\Interpreter;

use Pimcore\Bundle\DataImporterBundle\Exception\InvalidConfigurationException;
use Pimcore\Bundle\DataImporterBundle\Exception\InvalidInputException;
use Pimcore\Bundle\DataImporterBundle\PimcoreDataImporterBundle;
use Pimcore\Bundle\DataImporterBundle\Settings\PreviewData;
use Symfony\Component\Config\Util\Exception\XmlParsingException;
use Symfony\Component\Config\Util\XmlUtils;

class XmlFileInterpreter extends AbstractInterpreter
{
    /**
     * @var string
     */
    protected $xpath;

    /**
     * @var string
     */
    protected $schema;

    /**
     * @var \DOMDocument
     */
    protected $cachedContent = null;
    /**
     * @var string
     */
    protected $cachedFilePath = null;

    protected function loadData(string $path)
    {
        if ($this->cachedFilePath === $path && !empty($this->cachedContent)) {
            $schema = $this->schema;
            $dom = XmlUtils::loadFile($path, function ($dom) use ($schema) {
                return @$dom->schemaValidateSource($schema);
            });
        } else {
            $dom = $this->cachedContent;
        }

        $xpath = new \DOMXpath($dom);

        $result = $xpath->evaluate($this->xpath);
        if ($result instanceof \DOMNodeList) {
            return $result;
        } else {
            throw new InvalidInputException(sprintf('Item path `%s` not found.', $this->xpath));
        }
    }

    protected function doInterpretFileAndCallProcessRow(string $path): void
    {
        $records = $this->loadData($path);

        foreach ($records as $item) {
            $this->processImportRow(XmlUtils::convertDomElementToArray($item));
        }
    }

    public function fileValid(string $path, bool $originalFilename = false): bool
    {
        $this->cachedContent = null;
        $this->cachedFilePath = null;

        if ($originalFilename) {
            $filename = $path;
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if ($ext !== 'xml') {
                return false;
            }
        }

        try {
            $schema = $this->schema;
            $dom = XmlUtils::loadFile($path, function ($dom) use ($schema) {
                return @$dom->schemaValidateSource($schema);
            });
        } catch (XmlParsingException $exception) {
            $message = 'Error validating XML: ' . $exception->getMessage();
            $this->applicationLogger->info($message, [
                'component' => PimcoreDataImporterBundle::LOGGER_COMPONENT_PREFIX . $this->configName,
            ]);

            return false;
        }

        $this->cachedContent = $dom;
        $this->cachedFilePath = $path;

        return true;
    }

    public function previewData(string $path, int $recordNumber = 0, array $mappedColumns = []): PreviewData
    {
        $previewData = [];
        $columns = [];
        $readRecordNumber = 0;

        if ($this->fileValid($path)) {
            $records = $this->loadData($path);
            $previewDataItem = $records->item($recordNumber);

            if (empty($previewDataItem)) {
                $readRecordNumber = $records->count() - 1;
                $previewDataItem = $records->item($readRecordNumber);
            } else {
                $readRecordNumber = $recordNumber;
            }

            if (!empty($previewDataItem) && $previewDataItem instanceof \DOMElement) {
                $previewData = XmlUtils::convertDomElementToArray($previewDataItem);

                $keys = array_keys($previewData);
                $columns = array_combine($keys, $keys);
            }
        }

        return new PreviewData($columns, $previewData, $readRecordNumber, $mappedColumns);
    }

    public function setSettings(array $settings): void
    {
        if (empty($settings['xpath'])) {
            throw new InvalidConfigurationException('Empty XPath.');
        }
        $this->xpath = $settings['xpath'];
        $this->schema = $settings['schema'];
    }
}
