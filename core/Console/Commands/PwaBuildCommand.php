<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Console\Input;
use Core\Console\Output;
use Core\Pwa\PwaBuilder;

/**
 * @file PwaBuildCommand.php
 * @brief Generates the web app manifest and icons.
 */
final class PwaBuildCommand extends Command
{
    /**
     * @brief PwaBuildCommand constructor.
     *
     * @param PwaBuilder $builder Manifest and icon generator.
     */
    public function __construct(private readonly PwaBuilder $builder)
    {
    }

    /** @brief Command name. @return string */
    public function name(): string
    {
        return 'pwa:build';
    }

    /** @brief Command description. @return string */
    public function description(): string
    {
        return 'Generate the web app manifests and icons from config/pwa.php';
    }

    /**
     * @brief Declares options.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'force'      => 'Regenerate icons that already exist (after changing pwa.icon or colors)',
            'if-enabled' => 'Do nothing (and succeed) when PWA support is disabled (used by the installer)',
        ];
    }

    /**
     * @brief Returns additional help.
     *
     * @return string
     */
    public function help(): string
    {
        return "Enable the app in config/local.php, for example:\n"
            . "  'pwa' => ['enabled' => true, 'name' => 'My App', 'short_name' => 'App', 'icon' => 'app/Assets/img/logo.png'],\n"
            . "then run this command. Further apps for parts of the site go to 'pwa' => ['apps' => [...]]\n"
            . "(see config/pwa.php). Icons need the PHP GD extension, SVG icons also rsvg-convert.";
    }

    /**
     * @brief Generates the files.
     *
     * @param Input $input Parsed input.
     * @param Output $output Console output.
     * @return int
     */
    public function handle(Input $input, Output $output): int
    {
        if (!PwaBuilder::isEnabled()) {
            if ($input->flag('if-enabled')) {
                return 0;
            }
            $output->warning("PWA support is disabled: set 'pwa' => ['enabled' => true] in config/local.php first.");
            return 1;
        }

        foreach ($this->builder->build($input->flag('force')) as $file) {
            $output->success("Written public/{$file}");
        }

        $output->comment('Pages using frasm_head() now link the manifest and register the service worker.');
        return 0;
    }
}
