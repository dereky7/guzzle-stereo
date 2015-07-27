<?php

/**
 * This file is part of the GuzzleStereo package.
 *
 * (c) Christophe Willemsen <willemsen.christophe@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ikwattro\GuzzleStereo;

use Ikwattro\GuzzleStereo\Exception\RecorderException;
use Ikwattro\GuzzleStereo\Formatter\ResponseFormatter;
use Ikwattro\GuzzleStereo\Record\Tape;
use Ikwattro\GuzzleStereo\Store\Writer;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class Recorder
{
    /**
     * @var string
     */
    protected $storeDirectory;

    /**
     * @var null|array
     */
    protected $config;

    /**
     * @var \Ikwattro\GuzzleStereo\Record\Tape[]
     */
    protected $tapes = [];

    /**
     * @var \Ikwattro\GuzzleStereo\Store\Writer
     */
    protected $writer;

    /**
     * @var \Ikwattro\GuzzleStereo\Formatter\ResponseFormatter
     */
    protected $formatter;

    /**
     * @param string      $storeDirectory
     * @param null|string $configurationFile
     */
    public function __construct($storeDirectory, $configurationFile = null)
    {
        $this->storeDirectory = $storeDirectory;
        $this->writer = new Writer($this->storeDirectory);
        $this->formatter = new ResponseFormatter();
        if ($configurationFile) {
            $this->loadConfig($configurationFile);
        }
    }

    /**
     * @param \Psr\Http\Message\ResponseInterface $response
     */
    public function record(ResponseInterface $response)
    {
        foreach ($this->tapes as $tape) {
            $tape->record($response);
        }
    }

    /**
     * @param string $configurationFile
     */
    public function loadConfig($configurationFile)
    {
        try {
            $this->config = Yaml::parse(file_get_contents($configurationFile));
        } catch (ParseException $e) {
            throw new RecorderException($e->getMessage(), $e->getCode(), $e->getPrevious());
        }
        $this->processConfig();
    }

    /**
     * @return null|string
     */
    public function getStore()
    {
        return $this->storeDirectory;
    }

    /**
     * @return null|array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @return \Ikwattro\GuzzleStereo\Record\Tape[]
     */
    public function getTapes()
    {
        return $this->tapes;
    }

    /**
     * @param \Ikwattro\GuzzleStereo\Record\Tape $tape
     */
    public function addTape(Tape $tape)
    {
        if (array_key_exists($tape->getName(), $this->tapes)) {
            throw new RecorderException(sprintf('A tape with name %s is already registered', $tape->getName()));
        }

        $this->tapes[$tape->getName()] = $tape;
    }

    /**
     * @param string $name
     *
     * @return \Ikwattro\GuzzleStereo\Record\Tape
     * @throw \Ikwattro\GuzzleStereo\Exception\RecordException When the tape can not be found
     */
    public function getTape($name)
    {
        if (!array_key_exists($name, $this->tapes)) {
            throw new RecorderException(sprintf('There is no tape with name "%s" registered', $name));
        }

        return $this->tapes[$name];
    }

    /**
     * Process configuration for registering tapes and filters.
     */
    private function processConfig()
    {
        $allowedFilters = [
            'status_code' => '\Ikwattro\GuzzleStereo\Filter\StatusCode',
            'non_empty_body' => '\Ikwattro\GuzzleStereo\Filter\NonEmptyBody',
            'has_header' => '\Ikwattro\GuzzleStereo\Filter\HasHeader',
        ];
        $tapes = isset($this->config['tapes']) ? $this->config['tapes'] : [];
        foreach ($tapes as $name => $settings) {
            $tape = new Tape($name);
            if (isset($settings['filters'])) {
                foreach ($settings['filters'] as $filter => $args) {
                    if (!is_array($args)) {
                        $f = new $allowedFilters[$filter]($args);
                    } else {
                        $f = new $allowedFilters[$filter](...$args);
                    }
                    $tape->addFilter($f);
                }
            }
            $this->addTape($tape);
        }
    }

    /**
     * @return \Ikwattro\GuzzleStereo\Store\Writer
     */
    public function getWriter()
    {
        return $this->writer;
    }

    /**
     * Dumps the tapes on disk.
     */
    public function dump()
    {
        foreach ($this->tapes as $tape) {
            if ($tape->hasResponses()) {
                $fileName = 'record_'.$tape->getName().'.json';
                $content = $this->formatter->encodeResponsesCollection($tape->getResponses());
                $this->writer->write($fileName, $content);
            }
        }
    }

    /**
     * Returns the content of a specific tape without writing it to disk.
     *
     * @param string $name
     *
     * @return null|string
     */
    public function getTapeContent($name)
    {
        $tape = $this->getTape($name);
        if ($tape->hasResponses()) {
            $content = $this->formatter->encodeResponsesCollection($tape->getResponses());

            return $content;
        }

        return;
    }

    /**
     * @return \Ikwattro\GuzzleStereo\Formatter\ResponseFormatter
     */
    public function getResponseFormatter()
    {
        return $this->formatter;
    }
}
