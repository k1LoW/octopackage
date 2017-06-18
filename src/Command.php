<?php

namespace Lab101000\Octopackage;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Composer\Command\BaseCommand;
use Composer\Semver\VersionParser as Semver;
use Composer\Semver\Comparator;
use Composer\Package\Version\VersionParser;

if (file_exists(dirname(__DIR__) . '/vendor/guzzlehttp')) {
    require_once dirname(__DIR__) . '/vendor/guzzlehttp/guzzle/src/functions.php';
    require_once dirname(__DIR__) . '/vendor/guzzlehttp/psr7/src/functions.php';
    require_once dirname(__DIR__) . '/vendor/guzzlehttp/promises/src/functions.php';
} else if (file_exists(dirname(dirname(dirname(dirname(__DIR__)))) . '/vendor/guzzlehttp')) {
    require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/vendor/guzzlehttp/guzzle/src/functions.php';
    require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/vendor/guzzlehttp/psr7/src/functions.php';
    require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/vendor/guzzlehttp/promises/src/functions.php';
}

class Command extends BaseCommand
{
    protected function configure()
    {
        $this->setName('octopackage')
            ->setDescription('octorelease for composer.')
            ->setDefinition(array(
                new InputOption('patch', null, InputOption::VALUE_NONE, 'Update patch version'),
                new InputOption('minor', null, InputOption::VALUE_NONE, 'Update minor version'),
                new InputOption('major', null, InputOption::VALUE_NONE, 'Update major version'),
            ));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer(false);
        $io = $this->getIO();
        $configFile = getenv('HUB_CONFIG') ? getenv('HUB_CONFIG') : getenv('HOME') . '/.config/hub';
        $config = Yaml::parse(file_get_contents($configFile));

        if (empty($config['github.com'][0]['oauth_token'])) {
            $io->writeError("<warning>Invalid {$configFile}</warning>");
            return 1;
        }

        $client = new \Github\Client();
        
        $client->authenticate(
            $config['github.com'][0]['oauth_token'],
            \Github\Client::AUTH_URL_TOKEN
        );

        $this->versionParser = new VersionParser;
        $package = $this->getComposer()->getPackage();

        $process = new Process('git tag');
        $process->mustRun();
        $tags = explode("\n", $process->getOutput());
        $previousVersion = '0.0.0.0';
        $v0TagExist = false;
        $semver = new Semver();
        foreach ($tags as $tag) {
            try {
                $normalizedTag = $semver->normalize($tag);
                if ($normalizedTag === '0.0.0.0') {
                    $v0TagExist = true;
                }
                if (Comparator::greaterThan($semver->normalize($tag), $previousVersion)) {
                    $previousVersion = $semver->normalize($tag);
                }
            } catch (\Exception $e) {
            }
        }

        if ($previousVersion !== '0.0.0.0' || $v0TagExist) {
            $previousVersionTag = $this->toVersionTag($previousVersion);
            $process = new Process("git log {$previousVersionTag}.. --grep=Merge");
        } else {
            $process = new Process('git log --grep=Merge');
        }
        $process->mustRun();
        $log = $process->getOutput();
        $process = new Process('git remote -v | grep origin');
        $process->mustRun();
        if (!preg_match('/([\w-]+\/[\w-]+)\.git/', $process->getOutput(), $matches)) {
            $io->writeError('<warning>Could not find remote orgin</warning>');
            return 1;
        }        
        $ownerRepo = explode('/', $matches[1]);
        $owner = $ownerRepo[0];
        $repo = $ownerRepo[1];

        if ($input->getOption('patch')) {
            $currentVersion = $this->bumpUpVersionNumber($previousVersion, 'patch');
        } else if ($input->getOption('minor')) {
            $currentVersion = $this->bumpUpVersionNumber($previousVersion, 'minor');
        } else if ($input->getOption('major')) {
            $currentVersion = $this->bumpUpVersionNumber($previousVersion, 'major');
        } else {
            $currentVersion = $this->bumpUpVersionNumber($previousVersion, 'patch');
        }
        $currentVersionTag = $this->toVersionTag($currentVersion);

        if (!$io->askConfirmation("Package {$currentVersionTag}? [yes]: ", true)) {
            return 0;
        }

        $process = new Process("git tag {$currentVersionTag}");
        $process->mustRun();
        $io->write("Tagged {$currentVersionTag}.");
        $process = new Process('git push && git push --tags');
        $process->mustRun();
        $io->write('Pushed commits and tags.');

        $logLines = explode('commit', $log);
        $description = array();
        foreach ($logLines as $lines) {
            if (!preg_match('/Merge pull request \#(\d+)/', $lines, $matches)) {
                continue;
            }
            $pullId = $matches[1];
            $url = "https://github.com/{$owner}/{$repo}/pull/{$pullId}";
            $pr = $client->api('pull_request')->show($owner, $repo, $pullId);
            $title = $pr['title'];
            $description[] = "* [{$title}]({$url})";
            $comment = $client->api('issue')->comments()->create($owner, $repo, $pullId, array(
                'body' => "Released as {$currentVersionTag}."
            ));
            $io->write("Added a release comment to the pull request #{$pullId}");
        }

        $client->api('repo')->releases()->create($owner, $repo, array(
            'tag_name' => $currentVersionTag,
            'name' => $currentVersionTag,
            'body' => implode("\n", $description)
        ));
        
        $io->write("Create a release {$currentVersionTag}");
        $io->write("https://github.com/{$owner}/{$repo}/releases/tag/{$currentVersionTag}");
    }

    /**
     * toVersionTag
     *
     */
    private function toVersionTag($version)
    {
        $semver = new Semver();
        $semvered = $semver->normalize($version);
        if (preg_match('/\.0$/', $semvered)) {
            $semvered = preg_replace('/\.0$/', '', $semvered);
        }
        return "v{$semvered}";
    }

    private function bumpUpVersionNumber($version, $type = 'patch')
    {
        $semver = new Semver();
        $semvered = $semver->normalize($version);
        $versions = explode('.', $semvered);
        switch ($type) {
        case 'patch':
            $versions[2]++;
            break;
        case 'minor':
            $versions[1]++;
            $versions[2] = 0;
            break;
        case 'major':
            $versions[0]++;
            $versions[1] = 0;
            $versions[2] = 0;
            break;
        }
        return implode('.', $versions);
    }
}