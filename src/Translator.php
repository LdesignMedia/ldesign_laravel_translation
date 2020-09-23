<?php namespace LdesignMedia\Translation;

use Cassandra\Collection;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Loader;

class Translator extends \Illuminate\Translation\Translator
    implements \Symfony\Contracts\Translation\TranslatorInterface {

    /**
     * @var Application
     */
    protected $app;

    /**
     * @var Loader
     */
    private $database;

    /**
     * Translator constructor.
     *
     * @param Loader      $database
     * @param Loader      $loader
     * @param             $locale
     * @param Application $app
     */
    public function __construct(Loader $database, Loader $loader, $locale, Application $app) {
        $this->database = $database;
        $this->app = $app;
        parent::__construct($loader, $locale);
    }

    /**
     * @param $namespace
     *
     * @return bool
     */
    protected static function isNamespaced($namespace) {
        return !(is_null($namespace) || $namespace == '*');
    }

    /**
     * Get the translation for the given key.
     *
     * @param string $key
     * @param array  $replace
     * @param null   $locale
     * @param bool   $fallback
     *
     * @return string
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true) {
        [$namespace, $group, $item] = $this->parseKey($key);

        // Here we will get the locale that should be used for the language line. If one
        // was not passed, we will use the default locales which was given to us when
        // the translator was instantiated. Then, we can load the lines and return.
        $locales = $this->parseLocale($locale);
        foreach ($locales as $local) {
            $this->load($namespace, $group, $local);

            $line = $this->getLine(
                $namespace, $group, $local, $item, $replace
            );

            // If we cannot find the translation group in the database nor as a file
            // an entry in the database will be added to the translations.
            // Keep in mind that a file cannot be used from that point.
            if (!self::isNamespaced($namespace) && is_null($line)) {
                // Database stuff
                $this->database->addTranslation($local, $group, $key);
            }

            if (!is_null($line)) {
                break;
            }
        }

        // If the line doesn't exist, we will return back the key which was requested as
        // that will be quick to spot in the UI if language keys are wrong or missing
        // from the application's language files. Otherwise we can return the line.
        if (!isset($line)) {
            return $key;
        }

        return $line;
    }

    /**
     * Get the array of locales to be checked.
     *
     * @param string|null $locale
     *
     * @return array
     */
    protected function parseLocale(?string $locale) : array {
        return array_filter([$locale ?: $this->locale, $this->fallback]);
    }

    /**
     * @param string $namespace
     * @param string $group
     * @param string $locale
     */
    public function load($namespace, $group, $locale) {
        if ($this->isLoaded($namespace, $group, $locale)) {
            return;
        }

        // If a Namespace is give the Filesystem will be used
        // otherwise we'll use our database.
        // This will allow legacy support.
        if (!self::isNamespaced($namespace)) {
            // If debug is off then cache the result forever to ensure high performance.
            if (!\Config::get('app.debug') || \Config::get('translation-db.minimal')) {
                $that = $this;
                $lines = \Cache::rememberForever('__translations.' . $locale . '.' . $group, function () use ($that, $locale, $group, $namespace) {
                    return $that->loadFromDatabase($namespace, $group, $locale);
                });
            } else {
                $lines = $this->loadFromDatabase($namespace, $group, $locale);
            }
        } else {
            $lines = $this->loader->load($locale, $group, $namespace);
        }
        $this->loaded[$namespace][$group][$locale] = $lines;
    }

    /**
     * @param $namespace
     * @param $group
     * @param $locale
     *
     * @return array
     */
    protected function loadFromDatabase($namespace, $group, $locale)  {
        $lines = $this->database->load($locale, $group, $namespace);
        if (count($lines) == 0 && \Config::get('translation-db.file_fallback', false)) {
            $lines = $this->loader->load($locale, $group, $namespace);
            return $lines;
        }

        return $lines;
    }

    /**
     * Get the translation for a given key.
     *
     * @param string $id
     * @param array  $parameters
     * @param string $domain
     * @param null   $locale
     *
     * @return string|array|null
     */
    public function trans($id, array $parameters = [], $domain = 'messages', $locale = null) {
        $string = $this->get($id, $parameters, $locale);

        if ($this->editing) {
            $this->editing_array[$id] = $string;
        }

        return $string;
    }

    /**
     * Get a translation according to an integer value.
     *
     * @param string               $id
     * @param int|array|\Countable $number
     * @param array                $parameters
     * @param string               $domain
     * @param null                 $locale
     *
     * @return string
     */
    public function transChoice($id, $number, array $parameters = [], $domain = 'messages', $locale = null) {
        return $this->choice($id, $number, $parameters, $locale);
    }
}
