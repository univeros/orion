<?php

declare(strict_types=1);

namespace Orion\Solr;

use Orion\IndexName;

use function dirname;
use function file_get_contents;
use function hash;
use function substr;

/**
 * Orion's configset (`configset/` in this package): uploaded once per cluster under a name carrying a hash of its
 * files, so a changed configset is a new name and collections built from an older one keep theirs. SolrCloud keeps a
 * managed schema in its configset, so each collection gets its own copy of the base (`orion.<collection>`, a name no
 * base can take), and the fields Orion adds to one collection never reach another.
 */
final class Configset
{
    private const array FILES = ['solrconfig.xml', 'managed-schema.xml'];

    public static function name(): string
    {
        $contents = '';
        foreach (self::FILES as $file) {
            $contents .= $file . "\0" . self::read($file) . "\0";
        }

        return 'orion-' . substr(hash('sha256', $contents), 0, 12);
    }

    /**
     * Uploads the configset, file by file (no archive needed), every time a collection is created: an upload a crash
     * interrupted leaves the name listed with a file missing, and only uploading again repairs it. The files are the
     * same each time, so concurrent uploads agree.
     */
    public static function ensure(Http $http): string
    {
        $name = self::name();
        foreach (self::FILES as $file) {
            Http::ok($http->bytes('admin/configs', ['action' => 'UPLOAD', 'name' => $name, 'filePath' => $file, 'overwrite' => 'true'], self::read($file)), 'upload the configset ' . $name);
        }

        return $name;
    }

    /**
     * A fresh copy of the base for a collection about to be created; a copy a crash left behind is replaced.
     */
    public static function forCollection(Http $http, IndexName $collection): string
    {
        $base = self::ensure($http);
        $own = self::own($collection);
        $http->get('admin/configs', ['action' => 'DELETE', 'name' => $own]);
        Http::ok($http->get('admin/configs', ['action' => 'CREATE', 'name' => $own, 'baseConfigSet' => $base, 'configSetProp.immutable' => 'false']), 'copy the configset for ' . $collection->value);

        return $own;
    }

    /**
     * Deletes a dropped collection's copy; nothing when there is none, or a collection still uses it.
     */
    public static function release(Http $http, IndexName $collection): void
    {
        $http->get('admin/configs', ['action' => 'DELETE', 'name' => self::own($collection)]);
    }

    private static function own(IndexName $collection): string
    {
        return 'orion.' . $collection->value;
    }

    private static function read(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/configset/' . $file);
    }
}
