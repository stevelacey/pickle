<?php
namespace Pickle;

class BuildSrcWindows
{
    use FileOps;

    private $pkg;
    private $options;
    private $log = '';
    private $cwdBack;
    private $tempDir;

    public function __construct(Package $pkg, $options = null)
    {
        $this->pkg = $pkg;
        $this->options = $options;
        $this->cwdBack = getcwd();
    }

    /**
     * @param integer $level
     * @param string  $msg
     */
    public function log($level, $msg)
    {
        $this->log .= $level . ': ' . $msg . "\n";
    }

    /**
     * @param string $src
     */
    private function copySrcDir($src, $dest)
    {
        foreach (scandir($src) as $file) {
            $srcfile = rtrim($src, '/') .'/'. $file;
            $destfile = rtrim($dest, '/') .'/'. $file;
            if (!is_readable($srcfile)) {
                continue;
            }
            if ($file != '.' && $file != '..') {
                if (is_dir($srcfile)) {
                    if (!is_dir($destfile)) {
                        mkdir($destfile);
                    }
                    $this->copySrcDir($srcfile, $destfile);
                } else {
                    copy($srcfile, $destfile);
                }
            }
        }
    }

    public function phpize()
    {
        $backCwd = getcwd();
        chdir($this->pkg->getRootDir());

        $res = $this->runCommand('phpize');
        chdir($backCwd);
        if (!$res) {
            throw new \Exception('phpize failed');
        }
    }

    public function configure()
    {
        /* duplicate src tree to do not pollute repo or src dir */
        $this->copySrcDir($this->pkg->getRootDir(), $this->tempDir);
        $backCwd = getcwd();
        chdir($this->tempDir);
        $configureOptions = '';
        foreach ($this->options as $name => $option) {
            if ('enable' === $option->type) {
                $decision = true == $option->input ? 'enable' : 'disable';
            } elseif ('disable' == $option->type) {
                $decision = false == $option->input ? 'enable' : 'disable';
            }

            $configureOptions .= ' --' . $decision . '-' . $name;
        }

        $extEnableOption = $this->options[$this->pkg->getName()];
        if ('enable' == $extEnableOption->type) {
            $confOption = '--enable-' . $this->pkg->getName() . '=shared';
        } else {
            $confOption = '--with-' . $this->pkg->getName() . '=shared';
        }
        $configureOptions = $confOption . ' ' . $configureOptions;

        $res = $this->runCommand($this->pkg->getRootDir() . '/configure '. $configureOptions);
        chdir($backCwd);
        if (!$res) {
            throw new \Exception('configure failed, see log at '. $this->tempDir . '\config.log');
        }
    }

    public function build()
    {
        $backCwd = getcwd();
        chdir($this->tempDir);
        $res = $this->runCommand('nmake');
        chdir($backCwd);

        if (!$res) {
            throw new \Exception('nmake failed');
        }
    }

    public function install()
    {
        $backCwd = getcwd();
        chdir($this->tempDir);
        $res = $this->runCommand('nmake install');
        chdir($backCwd);
        if (!$res) {
            throw new \Exception('nmake install failed');
        }
    }

    /**
     * @param  string     $command
     * @return boolean
     * @throws \Exception
     */
    private function runCommand($command)
    {
        $this->log(1, 'running: ' . $command);
        $pp = popen("$command 2>&1", 'r');
        if (!$pp) {
            throw new \Exception(
                'Failed to run the following command: ' . $command
            );
        }

        while ($line = fgets($pp, 1024)) {
            $this->log(2, rtrim($line));
        }

        $exitCode = is_resource($pp) ? pclose($pp) : -1;

        return (0 === $exitCode);
    }

    public function getLog()
    {
        return $this->log;
    }
}
