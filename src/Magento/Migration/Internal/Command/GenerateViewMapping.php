<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Migration\Internal\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateViewMapping extends Command
{
    /** @var bool */
    private $handlerFound;

    /** @var string */
    private $layoutResult;

    /** @var string */
    protected $m2LayoutHandles;

    /** @var array[] */
    protected $areas = [
        'adminhtml' => ['default', 'enterprise'],
        'frontend' => ['base', 'default', 'enterprise'],
    ];
    protected $moduleMapping = [];

    /** @var \Magento\Framework\Simplexml\ConfigFactory */
    protected $configFactory;

    /** @var \Magento\Framework\Filesystem\Driver\File */
    protected $file;

    /** @var \Magento\Migration\Logger\Logger */
    protected $logger;

    /**
     * @param \Magento\Framework\Simplexml\ConfigFactory $configFactory
     * @param \Magento\Framework\Filesystem\Driver\File $file
     * @param \Magento\Migration\Logger\Logger $logger
     *
     * @throws \LogicException When the command name is empty
     */
    public function __construct(
        \Magento\Framework\Simplexml\ConfigFactory $configFactory,
        \Magento\Framework\Filesystem\Driver\File $file,
        \Magento\Migration\Logger\Logger $logger
    ) {
        $this->configFactory = $configFactory;
        $this->file = $file;
        $this->logger = $logger;
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('generateViewMapping')
            ->setDescription('Generate view mapping from M1 to M2')
            ->addArgument(
                'm1',
                InputArgument::REQUIRED,
                'Base directory of M1'
            )
            ->addArgument(
                'm2',
                InputArgument::REQUIRED,
                'Base directory of M2'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $m1BaseDir = $input->getArgument('m1');
        $m2BaseDir = $input->getArgument('m2');

        if (!is_dir($m1BaseDir)) {
            $this->logger->error('m1 path doesn\'t exist or not a directory');
            exit;
        }


        if (!is_dir($m2BaseDir)) {
            $this->logger->error('m2 path doesn\'t exist or not a directory');
            exit;
        }

        //we only support base, default and enterprise
        foreach ($this->areas as $area => $subareas) {
            $tableNamesMapping = [];

            $m1ConfigFiles = $this->searchM1LayoutFiles($area, $m1BaseDir);
            $m2LayoutHandles = $this->buildM1LayoutStringFile($area, $m2BaseDir);

            foreach ($m1ConfigFiles as $configFile) {
                $content = $this->file->fileGetContents($configFile);
                $layoutM1 = new \Magento\Migration\Utility\M1\Layout($content);
                $mappingM1 = $this->mapView($layoutM1);


                //search m2 for layout handlers
                foreach ($mappingM1 as $layoutHandler) {
                    $this->handlerFound = false;
                    $this->layoutResult = $layoutHandler;

                    $this->isInM2Layout($layoutHandler);

                    //match some plurals
                    $layoutHandlerReplacement = $this->regexPlural($layoutHandler);
                    $this->isInM2Layout($layoutHandlerReplacement);

                    //some adminhtml prefixes were removed
                    if ($area == 'adminhtml') {
                        $layoutHandlerReplacement = $this->replaceAdminhtmlPrefix($layoutHandler);
                        $this->matchWordinBetween($layoutHandlerReplacement);
                        $this->matchOneWordAsPrefix($layoutHandlerReplacement);
                        $this->matchTwoWordsAsPrefix($layoutHandlerReplacement);
                    } else {
                        $this->processEnterprisePrefix($layoutHandler);
                        $this->processPagePrefix($layoutHandler);
                    }

                    $mappingM1[$layoutHandler] =
                        $this->handlerFound ? $this->layoutResult : 'obsolete';
                }
                $tableNamesMapping = array_merge($mappingM1, $tableNamesMapping);
            }

            $outputFileName = BP . '/mapping/view_mapping_' . $area . '.json';
            if (file_put_contents($outputFileName, strtolower(json_encode($tableNamesMapping, JSON_PRETTY_PRINT)))) {
                $this->logger->info($outputFileName . ' was generated');
            } else {
                $this->logger->error('Could not write ' . $outputFileName . '. check writing permissions');
            }
        }
    }

    /**
     * @param \Magento\Migration\Utility\M1\Layout $layout
     * @return array
     */
    protected function mapView($layout)
    {
        return $layout->getLayoutHandlers();
    }

    /**
     * @param string $area
     * @param string $m1BaseDir
     * @return string[]
     */
    protected function searchM1LayoutFiles($area, $m1BaseDir)
    {
        $m1ConfigFiles = [];
        foreach ($this->areas[$area] as $subarea) {
            $m1ConfigFiles = array_merge(
                $m1ConfigFiles,
                $this->file->search('design/' . $area .
                    '/' . $subarea . '/default/layout/*.xml', $m1BaseDir . '/app'),
                $this->file->search('design/' . $area .
                    '/' . $subarea . '/default/layout/*/*.xml', $m1BaseDir . '/app'),
                $this->file->search('design/' . $area .
                    '/' . $subarea . '/default/layout/*/*/*.xml', $m1BaseDir . '/app')
            );
        }
        return $m1ConfigFiles;
    }

    /**
     * @param string $area
     * @param string $m2BaseDir
     * @return string
     */
    protected function buildM1LayoutStringFile($area, $m2BaseDir)
    {
        $search = array_merge(
            glob($m2BaseDir . '/app/code/*/*/view/' . $area . '/layout/*.xml'),
            glob($m2BaseDir . '/app/code/*/*/view/base/layout/*.xml')
        );
        $m2LayoutHandles = '';
        foreach ($search as $fileName) {
            $m2LayoutHandles .= ' ' . preg_replace('/\.xml$/', '', basename($fileName)) . ' ';
        }
        $this->m2LayoutHandles = $m2LayoutHandles;
        return $m2LayoutHandles;
    }

    /**
     * @param string $str
     * @return string|false
     */
    private function isInM2Layout($str)
    {
        if (!$this->handlerFound) {
            $match = [];
            $this->handlerFound =
                $this->handlerFound || preg_match('/ (' . $str . ') /is', $this->m2LayoutHandles, $match);
            if ($this->handlerFound) {
                $this->layoutResult = $match[1];
                return $match[1];
            }
        }
        return false;
    }

    /**
     * @param string $str
     * @return string
     */
    private function regexPlural($str)
    {
        return str_replace('_', '.{0,1}_', $str);

    }

    /**
     * @param string $layoutHandler
     * @return void
     */
    private function processEnterprisePrefix($layoutHandler)
    {
        if (preg_match('/^enterprise\_/', $layoutHandler)) {
            $layoutHandlerReplacement = preg_replace('/^enterprise_/', 'magento_', $layoutHandler);
            $layoutHandlerReplacement = $this->regexPlural($layoutHandlerReplacement);
            $this->isInM2Layout($layoutHandlerReplacement);
        }
    }

    /**
     * @param string $layoutHandler
     * @return void
     */
    private function processPagePrefix($layoutHandler)
    {
        //page_ prefix
        $this->isInM2Layout('page_' . $layoutHandler);
    }

    /**
     * @param string $layoutHandler
     * @return string
     */
    private function replaceAdminhtmlPrefix($layoutHandler)
    {
        $layoutHandlerReplacement = preg_replace('/^adminhtml\_/', '', $layoutHandler);
        $this->isInM2Layout($layoutHandlerReplacement);
        return $layoutHandlerReplacement;

    }

    /**
     * @param string $layoutHandler
     * @return void
     */
    private function matchWordinBetween($layoutHandler)
    {
        //insert one word in between
        $this->isInM2Layout(str_replace('_', '_([^_]+)_', $layoutHandler));
    }

    /**
     * @param string $layoutHandler
     * @return void
     */
    private function matchOneWordAsPrefix($layoutHandler)
    {
        $this->isInM2Layout('([^_]+)_' . $layoutHandler);
    }

    /**
     * @param string $layoutHandler
     * @return void
     */
    private function matchTwoWordsAsPrefix($layoutHandler)
    {
        $this->isInM2Layout('([^_]+)_([^_]+)_' . $layoutHandler);
    }
}