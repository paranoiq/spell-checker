<?php declare(strict_types = 1);
/**
 * This file is part of the Dogma library (https://github.com/paranoiq/dogma)
 *
 * Copyright (c) 2012 Vlasta Neubauer (@paranoiq)
 *
 * For the full copyright and license information read the file 'license.md', distributed with this source code
 */

namespace Dogma\Tools;

use Dogma\Tools\Colors as C;
use Nette\Neon\Neon;

final class Configurator extends \stdClass
{

    public const FLAG = 'flag';
    public const FLAG_VALUE = 'flag-value';
    public const VALUE = 'value';
    public const VALUES = 'values';
    public const ENUM = 'enum';
    public const SET = 'set';

    /** @var string[][] */
    private $arguments;

    /** @var mixed[] */
    private $defaults;

    /** @var mixed[] */
    private $values = [];

    /** @var mixed[] */
    private $profiles = [];

    public function __construct(array $arguments, array $defaults = [])
    {
        $this->arguments = $arguments;
        $this->defaults = $defaults;
    }

    public function hasValues(): bool
    {
        foreach ($this->values as $value) {
            if ($value !== null) {
                return true;
            }
        }
        return false;
    }

    public function renderHelp(): string
    {
        $guide = '';
        foreach ($this->arguments as $name => $config) {
            if (is_string($config)) {
                $guide .= $config . "\n";
                continue;
            }
            $row = '';
            @list($short, $type, $info, $hint, $values) = $config;
            $row .= $short ? C::white('  -' . $short) : '    ';
            $row .= C::white(' --' . $name);
            if ($type === self::FLAG_VALUE || $type === self::VALUE || $type === self::VALUES || $type === self::ENUM || $type === self::SET) {
                $row .= C::gray($hint ? sprintf(' <%s>', $hint) : ' <value>');
            }
            $row = C::padString($row, 23);
            $row .= ' ' . $info;
            if ($type === self::ENUM || $type === self::SET) {
                $row .= '; values: ' . implode('|', array_map([C::class, 'lyellow'], $values));
            }
            if (isset($this->defaults[$name])) {
                if ($type === self::VALUES || $type === self::SET) {
                    $row .= '; default: ' . implode(',', array_map(function ($value) {
                        return C::lyellow($this->format($value));
                    }, $this->defaults[$name]));
                } else {
                    $row .= '; default: ' . C::lyellow($this->format($this->defaults[$name]));
                }
            }
            $guide .= $row . "\n";
        }

        return $guide . "\n";
    }

    public function loadCliArguments(): void
    {
        $short = [];
        $shortAlt = [];
        $long = [];
        $longAlt = [];
        foreach ($this->arguments as $name => $config) {
            if (is_string($config)) {
                continue;
            }
            [$shortcut, $type] = $config;
            if ($type === self::FLAG_VALUE) {
                if ($shortcut) {
                    $short[] = $shortcut . '';
                    $shortAlt[] = $shortcut . ':';
                }
                $long[] = $name . '';
                $longAlt[] = $name . ':';
            } else {
                if ($shortcut) {
                    $short[] = $shortcut . ($type === self::FLAG ? '' : ':');
                }
                $long[] = $name . ($type === self::FLAG ? '' : ':');
            }
        }

        $values = getopt(implode('', $short), $long);
        if ($shortAlt || $longAlt) {
            $altValues = getopt(implode('', $shortAlt), $longAlt);
            $values = array_merge($values, $altValues);
        }
        foreach ($this->arguments as $name => [$shortcut, $type]) {
            if (is_numeric($name)) {
                continue;
            }
            if (isset($values[$name])) {
                $value = $values[$name];
            } else {
                $value = null;
                if (isset($values[$shortcut])) {
                    $value = $values[$shortcut];
                }
            }
            if ($value === false) {
                $value = true;
            }
            $value = $this->normalize($value, $type);
            $values[$name] = $value;
            unset($values[$shortcut]);
        }
        $this->values = $values;
    }

    public function loadConfig(string $filePath): void
    {
        if (is_file($filePath)) {
            if (substr($filePath, -5) === '.neon') {
                $config = Neon::decode(file_get_contents($filePath));
            } elseif (substr($filePath, -4) === '.ini') {
                $config = parse_ini_file($filePath);
            } else {
                echo C::white("Error: Only .neon and .ini files are supported!\n\n", C::RED);
                exit(1);
            }
        } elseif ($filePath) {
            echo C::white(sprintf("Configuration file %s not found.\n\n", $filePath), C::RED);
            exit(1);
        } else {
            $config = [];
        }

        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $this->expandValues($config, $config[$key]);
            }
        }

        $this->loadValues($config, false);

        foreach ($config as $key => $value) {
            if ($key[0] === '@') {
                $this->profiles[$key] = $value;
            }
        }

        if ($this->values['use']) {
            foreach ($this->values['use'] as $use) {
                if (!isset($config[$use])) {
                    die(sprintf('Configuration profile %s not found.', $use));
                }
                $this->loadValues($config[$use], true);
            }
        }
    }

    private function expandValues(array $config, array &$section): void
    {
        while (isset($section['include'])) {
            $includes = $section['include'];
            unset($section['include']);
            $includes = is_array($includes) ? $includes : [$includes];
            foreach ($includes as $include) {
                $section = array_merge($section, $config[$include]);
            }
        }
        foreach ($section as $key => $value) {
            if (is_array($value)) {
                $this->expandValues($config, $section[$key]);
            }
        }
    }

    private function loadValues(array $config, bool $rewrite): void
    {
        foreach ($this->arguments as $name => [, $type]) {
            if (isset($config[$name]) && ($rewrite || !isset($this->values[$name]))) {
                $this->values[$name] = $this->normalize($config[$name]);
            }
        }
    }

    /**
     * @param string|string[]|null $value
     * @param string|null $type
     * @return string|int|float|string[]|int[]|float[]
     */
    private function normalize($value, ?string $type = null)
    {
        if (($type === self::VALUES || $type === self::SET) && is_string($value)) {
            $value = explode(',', $value);
            foreach ($value as &$item) {
                $item = $this->normalize($item);
            }
        } elseif (is_numeric($value)) {
            $value = (float) $value;
            if ($value === (float) (int) $value) {
                $value = (int) $value;
            }
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function format($value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        return (string) $value;
    }

    /**
     * @param string $name
     * @return \Dogma\Tools\ConfigurationProfile|mixed|null
     */
    public function __get(string $name)
    {
        if (isset($this->profiles['@' . $name])) {
            return new ConfigurationProfile($this->profiles['@' . $name]);
        }
        if (!array_key_exists($name, $this->values)) {
            trigger_error(sprintf('Value "%s" not found.', $name));
            return null;
        }
        if (!isset($this->values[$name]) && isset($this->defaults[$name])) {
            return $this->defaults[$name];
        } else {
            return $this->values[$name];
        }
    }

}
