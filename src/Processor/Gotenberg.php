<?php
declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\WebToPrintBundle\Processor;

use function array_merge;
use function file_exists;
use Gotenberg\Gotenberg as GotenbergAPI;
use Gotenberg\Stream;
use function json_decode;
use function key_exists;
use Pimcore\Bundle\WebToPrintBundle\Config;
use Pimcore\Bundle\WebToPrintBundle\Event\DocumentEvents;
use Pimcore\Bundle\WebToPrintBundle\Event\Model\PrintConfigEvent;
use Pimcore\Bundle\WebToPrintBundle\Model\Document\PrintAbstract;
use Pimcore\Bundle\WebToPrintBundle\Processor;
use Pimcore\Logger;

class Gotenberg extends Processor
{
    /**
     * @internal
     */
    protected function buildPdf(PrintAbstract $document, object $config): string
    {
        $params = ['document' => $document];
        $this->updateStatus($document->getId(), 10, 'start_html_rendering');
        $html = $document->renderDocument($params);

        $this->updateStatus($document->getId(), 40, 'finished_html_rendering');

        try {
            $this->updateStatus($document->getId(), 50, 'pdf_conversion');
            $pdf = $this->getPdfFromString($html);
            $this->updateStatus($document->getId(), 100, 'saving_pdf_document');
        } catch (\Exception $e) {
            Logger::error((string) $e);
            $document->setLastGenerateMessage($e->getMessage());

            throw new \Exception('Error during PDF-Generation:' . $e->getMessage());
        }

        $document->setLastGenerateMessage('');

        return $pdf;
    }

    /**
     * @internal
     */
    public function getProcessingOptions(): array
    {
        $event = new PrintConfigEvent($this, [
            'options' => [],
        ]);
        \Pimcore::getEventDispatcher()->dispatch($event, DocumentEvents::PRINT_MODIFY_PROCESSING_OPTIONS);

        return (array)$event->getArgument('options');
    }

    /**
     * @internal
     */
    public function getPdfFromString(string $html, array $params = [], bool $returnFilePath = false): string
    {
        $web2printConfig = Config::getWeb2PrintConfig();

        $processParams = [
            'hostUrl' => $web2printConfig['gotenbergHostUrl'] ?? 'http://nginx:80',
        ];

        $html = $this->processHtml($html, $processParams);

        $gotenbergSettings = $web2printConfig['gotenbergSettings'] ?? '';
        $gotenbergSettings = json_decode($gotenbergSettings, true);

        if ($gotenbergSettings) {
            foreach (['header', 'footer'] as $item) {
                if (key_exists($item, $gotenbergSettings) && $gotenbergSettings[$item] &&
                    file_exists($gotenbergSettings[$item])) {
                    $gotenbergSettings[$item . 'Template'] = $gotenbergSettings[$item];
                }
                unset($gotenbergSettings[$item]);
            }

            $params = array_merge($params, $gotenbergSettings);
        }

        $params = $params ?: $this->getDefaultOptions();

        $event = new PrintConfigEvent($this, [
            'params' => $params,
            'html' => $html,
        ]);

        \Pimcore::getEventDispatcher()->dispatch($event, DocumentEvents::PRINT_MODIFY_PROCESSING_CONFIG);

        ['html' => $html, 'params' => $params] = $event->getArguments();

        $tempFileName = uniqid('web2print_');

        $chromium = GotenbergAPI::chromium(\Pimcore\Config::getSystemConfiguration('gotenberg')['base_url']);
        // To support gotenberg-php v2 and so on
        if (method_exists($chromium, 'pdf')) {
            $chromium = $chromium->pdf();
        } else {
            // gotenberg-php v1 BC Layer for unsupported methods in v2
            if (isset($params['userAgent']) && method_exists($chromium, 'userAgent')) {
                $chromium->userAgent($params['userAgent']);
            }
            if (isset($params['pdfFormat'])&& method_exists($chromium, 'pdfFormat')) {
                $chromium->pdfFormat($params['pdfFormat']);
            }
        }

        $options = [
            'printBackground', 'landscape', 'preferCssPageSize', 'omitBackground', 'emulatePrintMediaType',
            'emulateScreenMediaType',
        ];

        foreach ($options as $option) {
            if (isset($params[$option]) && $params[$option] != false) {
                $chromium->$option();
            }
        }

        // generateDocumentOutline is only available for gotenberg >= 8.14.0 and gotenberg-php >= v2.10.0
        if (isset($params['generateDocumentOutline']) && $params['generateDocumentOutline']
            && method_exists($chromium, 'generateDocumentOutline')) {
            $chromium->generateDocumentOutline();
        }

        $chromium->margins(
            $params['marginTop'] ?? 0.39,
            $params['marginBottom'] ?? 0.39,
            $params['marginLeft'] ?? 0.39,
            $params['marginRight'] ?? 0.39
        );

        if (isset($params['scale'])) {
            $chromium->scale($params['scale']);
        }

        if (isset($params['nativePageRanges'])) {
            $chromium->nativePageRanges($params['nativePageRanges']);
        }

        foreach (['header', 'footer'] as $item) {
            if (isset($params[$item . 'Template'])) {
                $chromium->$item(Stream::path($params[$item . 'Template']));
            }
        }

        if ($params['paperWidth'] ?? isset($params['paperHeight'])) {
            $chromium->paperSize($params['paperWidth'] ?? 8.5, $params['paperHeight'] ?? 11);
        }

        if (isset($params['extraHttpHeaders'])) {
            $chromium->extraHttpHeaders($params['extraHttpHeaders']);
        }

        // metadata is only passed on gotenberg-php > 2.2
        if (isset($params['metadata']) && method_exists($chromium, 'metadata')) {
            $chromium->metadata($params['metadata']);
        }

        $request = $chromium->outputFilename($tempFileName)->html(Stream::string('processor.html', $html));

        if ($returnFilePath) {
            $filename = GotenbergAPI::save($request, PIMCORE_SYSTEM_TEMP_DIRECTORY);

            return PIMCORE_SYSTEM_TEMP_DIRECTORY . DIRECTORY_SEPARATOR . $filename;
        }
        $response = GotenbergAPI::send($request);

        return $response->getBody()->getContents();
    }

    private function getDefaultOptions(): array
    {
        return [
            //'paperWidth',
            //'paperHeight',
            //'marginTop',
            //'marginBottom',
            //'marginLeft',
            //'marginRight',
            //'preferCssPageSize',
            'printBackground' => true,
            //'omitBackground',
            'landscape' => false,
            //'scale' => 1,
            //'nativePageRanges',
            //'emulatePrintMediaType',
            //'emulateScreenMediaType',
            //'userAgent',
            //'extraHttpHeaders' => [],
            //'pdfFormat',
        ];
    }
}
