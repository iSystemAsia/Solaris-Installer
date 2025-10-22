<?php

namespace Solaris\Installer\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Illuminate\Support\ProcessUtils;
use Illuminate\Support\Str;
use RuntimeException;
use Solaris\Installer\Console\ConfiguresPrompts;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class NewCommand extends Command
{
    use ConfiguresPrompts;

    const DATABASE_DRIVERS = ["mysql", "mariadb", "pgsql", "sqlsrv", "sqlite"];
    const SOLARIS_PACKAGES = ["core", "master-data", "sales", "marketing", "service"];
    const SOLARIS_LARAVEL_PACKAGES = [
        "core" => [
            "isystemasia/solaris-laravel"
        ],
        "master-data" => [
            "isystemasia/solaris-laravel",
            "isystemasia/solaris-laravel-masterdata"
        ],
        "sales" => [
            "isystemasia/solaris-laravel",
            "isystemasia/solaris-laravel-masterdata",
            // "isystemasia/solaris-laravel-crm",
            "isystemasia/solaris-laravel-sales"
        ],
        "marketing" => [
            "isystemasia/solaris-laravel",
            "isystemasia/solaris-laravel-masterdata",
            // "isystemasia/solaris-laravel-crm",
            "isystemasia/solaris-laravel-marketing"
        ],
        "service" => [
            "isystemasia/solaris-laravel",
            "isystemasia/solaris-laravel-masterdata",
            // "isystemasia/solaris-laravel-crm",
            "isystemasia/solaris-laravel-service"
        ]
    ];

    protected $composer;

    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new solaris application')
            ->addArgument('name', InputArgument::OPTIONAL)
            ->addArgument('token', InputArgument::OPTIONAL)
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Install solaris package. Possible values are: '.implode(', ', self::SOLARIS_PACKAGES))
            ->addOption('laravel', null, InputOption::VALUE_NONE, 'PHP Laravel Backend')
            ->addOption('fiber', null, InputOption::VALUE_NONE, 'Go Fiber Backend')
            ->addOption('netcore', null, InputOption::VALUE_NONE, 'C# ASP.Net Core')
            ->addOption('blade', null, InputOption::VALUE_NONE, 'Install Laravel Blade Solar UI Starter Kit')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Install Solar Vue Starter Kit')
            ->addOption('react', null, InputOption::VALUE_NONE, 'Install Solar React Starter Kit')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'The database driver your application will use. Possible values are: '.implode(', ', self::DATABASE_DRIVERS))
            ->addOption('db_host', null, InputOption::VALUE_REQUIRED, 'Database host')
            ->addOption('db_port', null, InputOption::VALUE_REQUIRED, 'Database port')
            ->addOption('db_name', null, InputOption::VALUE_REQUIRED, 'Database name')
            ->addOption('db_username', null, InputOption::VALUE_REQUIRED, 'Database username')
            ->addOption('db_password', null, InputOption::VALUE_NONE, 'Database password')
            ->addOption('redis_db', null, InputOption::VALUE_NONE, 'Redis DB index')
            ->addOption('redis_cache_db', null, InputOption::VALUE_NONE, 'Redis Cache DB index')
            ->addOption('migrate', null, InputOption::VALUE_NONE, 'Doing migrate after install finish')
            ->addOption('seeder', null, InputOption::VALUE_NONE, 'Doing seeder after install finish')
            ->addOption('npm', null, InputOption::VALUE_NONE, 'Doing npm install after setup package.json finish');
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        parent::interact($input, $output);

        $this->configurePrompts($input, $output);

        $output->write(PHP_EOL.'  <fg=yellow>
 $$$$$$\            $$\                     $$\           
$$  __$$\           $$ |                    \__|          
$$ /  \__| $$$$$$\  $$ | $$$$$$\   $$$$$$\  $$\  $$$$$$$\ 
\$$$$$$\  $$  __$$\ $$ | \____$$\ $$  __$$\ $$ |$$  _____|
 \____$$\ $$ /  $$ |$$ | $$$$$$$ |$$ |  \__|$$ |\$$$$$$\  
$$\   $$ |$$ |  $$ |$$ |$$  __$$ |$$ |      $$ | \____$$\ 
\$$$$$$  |\$$$$$$  |$$ |\$$$$$$$ |$$ |      $$ |$$$$$$$  |
 \______/  \______/ \__| \_______|\__|      \__|\_______/ </>'.PHP_EOL.PHP_EOL);
        
        if (! $input->getArgument('name')) {
            $input->setArgument('name', text(
                label       : 'What is the name of your project?',
                placeholder : 'E.g. example-app',
                required    : 'The project name is required.',
                validate    : function ($value) {
                    if (preg_match('/[^\pL\pN\-_.]/', $value) !== 0) {
                        return 'The name may only contain letters, numbers, dashes, underscores, and periods.';
                    }
                },
            ));
        }

        $this->verifyApplicationDoesntExist(
            $this->getInstallationDirectory($input->getArgument('name'))
        );

        if (! $input->getArgument('token')) {
            $input->setArgument('token', password(
                label       : 'Enter your solaris token',
                required    : 'Token is required.'
            ));
        }

        if(! $input->getOption('package')) {
            $packageOptions  = $this->packageOptions();
            $defaultDatabase = collect($packageOptions)->keys()->first();

            $input->setOption('package', select(
                label   : 'Which package will your application use?',
                options : $this->packageOptions(),
                default : $defaultDatabase
            ));
        }

        if(!$input->getOption('laravel') && !$input->getOption('fiber') && !$input->getOption('netcore')) {
            match (select(
                label   : 'Which backend would you like to install?',
                options : [
                    'laravel'   => 'PHP Laravel',
                    'fiber'     => 'Go Fiber',
                    'netcore'   => 'C# ASP.Net Core',
                ],
                default: 'laravel',
            )) {
                'fiber'     => $input->setOption('fiber', true),
                'netcore'   => $input->setOption('netcore', true),
                default     => $input->setOption('laravel', true),
            };
        }

        if(!$input->getOption('blade') && !$input->getOption('vue') && !$input->getOption('react')) {
            $frontendOptions = [
                'blade' => 'Laravel Blade - Solar UI',
                'vue'   => 'Solar Vue',
                'react' => 'Solar React',
            ];

            if(!$input->getOption('laravel')) {
                unset($frontendOptions['blade']);
            }

            match (select(
                label   : 'Which frontend would you like to install?',
                options : $frontendOptions,
                default: $input->getOption('laravel') ? 'blade' : 'vue',
            )) {
                'blade' => $input->setOption('blade', true),
                'vue'   => $input->setOption('vue', true),
                'react' => $input->setOption('react', true)
            };
        }

        if (! $input->getOption('database')) {
            $databaseOptions = $this->databaseOptions();
            $defaultDatabase = collect($databaseOptions)->keys()->first();

            $input->setOption('database', select(
                label   : 'Which database will your application use?',
                options : $databaseOptions,
                default : $defaultDatabase
            ));
        }

        if(! $input->getOption('db_host')) {
            $input->setOption('db_host', text("Database host", default: "127.0.0.1"));
        }

        if(! $input->getOption('db_port')) {
            $input->setOption('db_port', text("Database port", default: $this->defaultDatabasePort($input->getOption('database'))));
        }

        if(! $input->getOption('db_name')) {
            $input->setOption('db_name', text("Database name", default: "solaris"));
        }

        if(! $input->getOption('db_username')) {
            $input->setOption('db_username', text("Database username", default: "root"));
        }

        if(! $input->getOption('db_password')) {
            $input->setOption('db_password', password("Database password"));
        }

        if(! $input->getOption('redis_db')) {
            $input->setOption('redis_db', text("Redis DB index", default: "0"));
        }

        if(! $input->getOption('redis_cache_db')) {
            $input->setOption('redis_cache_db', text("Redis Cache DB index", default: "1"));
        }

        if(! $input->getOption('npm')) {
            $input->setOption('npm', confirm("Would you like to run the npm install after package.json updated"));
        }

        if(! $input->getOption('migrate')) {
            $input->setOption('migrate', confirm("Would you like to run the database migrations"));
        }

        if(! $input->getOption('seeder')) {
            $input->setOption('seeder', confirm("Would you like to run the database seeder"));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validatePackageOption($input);
        $this->validateDatabaseOption($input);

        $name       = rtrim($input->getArgument('name'), '/\\');
        $token      = $input->getArgument("token");
        $directory  = $this->getInstallationDirectory($name);
        $package    = $input->getOption("package");
        
        if($input->getOption("laravel")) {
            $solarisConfig = [
                'npm-install'   => $input->getOption("npm"),
                'migrate'       => $input->getOption("migrate"),
                'seeder'        => $input->getOption("seeder")
            ];
            return $this->installLaravel($input, $output, $directory, $package, $token, $solarisConfig);
        }

        return 0;
    }

    protected function installLaravel(InputInterface $input, OutputInterface $output, string $directory, string $package, string $token, array $config): int
    {
        $this->ensureExtensionsAreAvailable($input, $output);

        $this->composer = new Composer(new Filesystem(), $directory);

        $composer  = $this->findComposer();
        $phpBinary = $this->phpBinary();

        $commands = [
            $composer." create-project laravel/laravel \"$directory\" --remove-vcs --prefer-dist --no-scripts",
            $composer." run post-root-package-install -d \"$directory\"",
            $phpBinary." \"$directory/artisan\" key:generate --ansi",
        ];

        if (PHP_OS_FAMILY != 'Windows') {
            $commands[] = "chmod 755 \"$directory/artisan\"";
        }

        if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
            file_put_contents($directory.'/auth.json', json_encode([
                'github-oauth' => ['github.com' => $token],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->appendGitignore($directory);

            $commands        = [];
            $solarisPackages = self::SOLARIS_LARAVEL_PACKAGES[$package];
            foreach ($solarisPackages as $pack) {
                $commands[] = $composer." config repositories.{$pack} vcs https://github.com/{$pack}.git";
            }

            $commands[] = $composer." require ".$solarisPackages[count($solarisPackages)-1]." --with-all-dependencies --no-cache";

            $this->runCommands($commands, $input, $output, workingPath: $directory);

            $solarisCommand = $phpBinary." \"$directory/artisan\" solaris:install";
            foreach ($config as $key => $value) {
                $solarisCommand .= " --".$key;
                if(is_string($value)) {
                    $solarisCommand .= '="{$value}"';
                }
            }

            $this->replaceInFile(
                'APP_URL=http://localhost',
                'APP_URL=http://localhost:8000',
                $directory.'/.env'
            );

            $this->configureDefaultDatabaseConnection($directory, [
                'db'            => $input->getOption("database"),
                'db_name'       => $input->getOption("db_name"),
                'db_host'       => $input->getOption("db_host"),
                'db_port'       => $input->getOption("db_port"),
                'db_username'   => $input->getOption("db_username"),
                'db_password'   => $input->getOption("db_password"),
            ]);

            $this->runCommands([$solarisCommand], $input, $output, workingPath: $directory);

            $commands = [
                $phpBinary." \"$directory/artisan\" reverb:install -q -n",
                $phpBinary." \"$directory/artisan\" install:broadcasting --without-node --force --reverb",
            ];
            $this->runCommands($commands, $input, $output, workingPath: $directory);
        }

        return $process->getExitCode();
    }

    protected function findComposer()
    {
        return implode(' ', $this->composer->findComposer());
    }

    protected function phpBinary()
    {
        $phpBinary = function_exists('Illuminate\Support\php_binary')
            ? \Illuminate\Support\php_binary()
            : (new PhpExecutableFinder)->find(false);

        return $phpBinary !== false
            ? ProcessUtils::escapeArgument($phpBinary)
            : 'php';
    }

    protected function ensureExtensionsAreAvailable(InputInterface $input, OutputInterface $output): void
    {
        $availableExtensions = get_loaded_extensions();

        $missingExtensions = collect([
            'ctype',
            'filter',
            'hash',
            'mbstring',
            'openssl',
            'session',
            'tokenizer',
        ])->reject(fn ($extension) => in_array($extension, $availableExtensions));

        if ($missingExtensions->isEmpty()) {
            return;
        }

        throw new \RuntimeException(
            sprintf('The following PHP extensions are required but are not installed: %s', $missingExtensions->join(', ', ', and '))
        );
    }

    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Application already exists!');
        }
    }

    protected function getInstallationDirectory(string $name)
    {
        return $name !== '.' ? getcwd().'/'.$name : '.';
    }

    protected function packageOptions(): array
    {
        return [
            "core"          => "Core",
            "master-data"   => "Master Data",
            "sales"         => "Sales",
            "marketing"     => "Marketing",
            "service"       => "Service"
        ];
    }

    protected function databaseOptions(): array
    {
        return collect([
            'mysql'     => ['MySQL', extension_loaded('pdo_mysql')],
            'mariadb'   => ['MariaDB', extension_loaded('pdo_mysql')],
            'pgsql'     => ['PostgreSQL', extension_loaded('pdo_pgsql')],
            'sqlsrv'    => ['SQL Server', extension_loaded('pdo_sqlsrv')],
            'sqlite'    => ['SQLite', extension_loaded('pdo_sqlite')]
        ])
        ->sortBy(fn ($database) => $database[1] ? 0 : 1)
        ->map(fn ($database) => $database[0].($database[1] ? '' : ' (Missing PDO extension)'))
        ->all();
    }

    protected function defaultDatabasePort($database)
    {
        $databases = [
            'mysql'     => '3306',
            'mariadb'   => '3306',
            'pgsql'     => '5432',
            'sqlsrv'    => '1433'
        ];
        
        return isset($databases[$database]) ? $databases[$database] : '3306';
    }

    protected function validateDatabaseOption(InputInterface $input)
    {
        if ($input->getOption('database') && ! in_array($input->getOption('database'), self::DATABASE_DRIVERS)) {
            throw new \InvalidArgumentException("Invalid database driver [{$input->getOption('database')}]. Possible values are: ".implode(', ', self::DATABASE_DRIVERS).'.');
        }
    }

    protected function validatePackageOption(InputInterface $input)
    {
        if ($input->getOption('package') && ! in_array($input->getOption('package'), self::SOLARIS_PACKAGES)) {
            throw new \InvalidArgumentException("Invalid solaris package [{$input->getOption('package')}]. Possible values are: ".implode(', ', self::SOLARIS_PACKAGES).'.');
        }
    }

    protected function runCommands($commands, InputInterface $input, OutputInterface $output, ?string $workingPath = null, array $env = [])
    {
        if (! $output->isDecorated()) {
            $commands = array_map(function ($value) {
                if (Str::startsWith($value, ['chmod', 'rm', 'git', $this->phpBinary().' ./vendor/bin/pest'])) {
                    return $value;
                }

                return $value.' --no-ansi';
            }, $commands);
        }

        $process = Process::fromShellCommandline(implode(' && ', $commands), $workingPath, $env, null, 0);

        if (Process::isTtySupported()) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('  <bg=yellow;fg=black> WARN </> '.$e->getMessage().PHP_EOL);
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write('    '.$line);
        });

        return $process;
    }

    protected function appendGitignore(string $directory)
    {
        $path = rtrim($directory, '/'). '/.gitignore';

        $lines = file_exists($path)
            ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            : [];

        if (!in_array('auth.json', $lines, true)) {
            $lines[] = 'auth.json';
            file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
        }
    }

    protected function replaceInFile(string|array $search, string|array $replace, string $file)
    {
        file_put_contents(
            $file,
            str_replace($search, $replace, file_get_contents($file))
        );
    }

    protected function pregReplaceInFile(string $pattern, string $replace, string $file)
    {
        file_put_contents(
            $file,
            preg_replace($pattern, $replace, file_get_contents($file))
        );
    }

    protected function configureDefaultDatabaseConnection(string $directory, array $dbConfig)
    {
        $database   = $dbConfig['db'];
        $dbName     = $dbConfig['db_name'];
        $dbHost     = $dbConfig['db_host'];
        $dbPort     = $dbConfig['db_port'];
        $dbUsername = $dbConfig['db_username'];
        $dbPassword = $dbConfig['db_password'];

        $this->pregReplaceInFile(
            '/DB_CONNECTION=.*/',
            'DB_CONNECTION='.$database,
            $directory.'/.env'
        );

        $this->pregReplaceInFile(
            '/DB_CONNECTION=.*/',
            'DB_CONNECTION='.$database,
            $directory.'/.env.example'
        );

        if ($database === 'sqlite') {
            $environment = file_get_contents($directory.'/.env');

            // If database options aren't commented, comment them for SQLite...
            if (! str_contains($environment, '# DB_HOST=127.0.0.1')) {
                $this->commentDatabaseConfigurationForSqlite($directory);

                return;
            }

            return;
        }

        // Any commented database configuration options should be uncommented when not on SQLite...
        $this->uncommentDatabaseConfiguration($directory);

        $this->replaceInFile(
            'DB_HOST=127.0.0.1',
            'DB_HOST='.$dbHost,
            $directory.'/.env'
        );

        $this->replaceInFile(
            'DB_HOST=127.0.0.1',
            'DB_HOST='.$dbHost,
            $directory.'/.env.example'
        );

        $this->replaceInFile(
            'DB_PORT=3306',
            'DB_PORT='.$dbPort,
            $directory.'/.env'
        );

        $this->replaceInFile(
            'DB_PORT=3306',
            'DB_PORT='.$dbPort,
            $directory.'/.env.example'
        );

        $this->replaceInFile(
            'DB_DATABASE=laravel',
            'DB_DATABASE='.str_replace('-', '_', strtolower($dbName)),
            $directory.'/.env'
        );

        $this->replaceInFile(
            'DB_DATABASE=laravel',
            'DB_DATABASE='.str_replace('-', '_', strtolower($dbName)),
            $directory.'/.env.example'
        );

        $this->replaceInFile(
            'DB_USERNAME=root',
            'DB_USERNAME='.$dbUsername,
            $directory.'/.env'
        );

        $this->replaceInFile(
            'DB_USERNAME=root',
            'DB_USERNAME='.$dbUsername,
            $directory.'/.env.example'
        );

        $this->replaceInFile(
            'DB_PASSWORD=',
            'DB_PASSWORD='.$dbPassword,
            $directory.'/.env'
        );

        $this->replaceInFile(
            'DB_PASSWORD=',
            'DB_PASSWORD='.$dbPassword,
            $directory.'/.env.example'
        );
    }

    protected function commentDatabaseConfigurationForSqlite(string $directory): void
    {
        $defaults = [
            'DB_HOST=127.0.0.1',
            'DB_PORT=3306',
            'DB_DATABASE=laravel',
            'DB_USERNAME=root',
            'DB_PASSWORD=',
        ];

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn ($default) => "# {$default}")->all(),
            $directory.'/.env'
        );

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn ($default) => "# {$default}")->all(),
            $directory.'/.env.example'
        );
    }

    protected function uncommentDatabaseConfiguration(string $directory)
    {
        $defaults = [
            '# DB_HOST=127.0.0.1',
            '# DB_PORT=3306',
            '# DB_DATABASE=laravel',
            '# DB_USERNAME=root',
            '# DB_PASSWORD=',
        ];

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn ($default) => substr($default, 2))->all(),
            $directory.'/.env'
        );

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn ($default) => substr($default, 2))->all(),
            $directory.'/.env.example'
        );
    }
} 