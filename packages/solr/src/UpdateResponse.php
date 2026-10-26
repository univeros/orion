<?php

declare(strict_types=1);

namespace Orion\Solr;

use Orion\Support\Assert;
use Orion\Write\WriteOutcome;

use function array_map;
use function is_array;
use function is_scalar;
use function str_starts_with;

/**
 * Reads an update's outcome per document. The configset's tolerant processor answers each refused document in the
 * header's `errors` by id; a version refusal (the outbox offset is not above the stored one) is stale, any other error
 * fails that document. A request that failed as a whole, or an answer without a header, fails every document: an
 * outcome is never assumed.
 */
final class UpdateResponse
{
    public const int MAX_REASON = 300;
    /** What Solr's DocBasedVersionConstraintsProcessor answers for an update or delete that is not newer. */
    private const string STALE = 'user version is not high enough';

    /**
     * @param array<array-key, mixed> $response
     * @param list<string> $ids the requested document ids
     * @return list<WriteOutcome>
     */
    public static function outcomes(array $response, array $ids): array
    {
        $header = is_array($response['responseHeader'] ?? null) ? $response['responseHeader'] : null;
        if ($header === null || ($header['status'] ?? null) !== 0) {
            $error = is_array($response['error'] ?? null) && is_scalar($response['error']['msg'] ?? null) ? (string) $response['error']['msg'] : 'Solr did not acknowledge the update.';

            return array_map(static fn(string $id): WriteOutcome => WriteOutcome::failed($id, Assert::truncate($error, self::MAX_REASON)), $ids);
        }
        $errors = [];
        foreach (is_array($header['errors'] ?? null) ? $header['errors'] : [] as $error) {
            if (is_array($error) && is_scalar($error['id'] ?? null)) {
                $errors[(string) $error['id']] = is_scalar($error['message'] ?? null) ? (string) $error['message'] : 'Solr refused the document.';
            }
        }

        return array_map(static function (string $id) use ($errors): WriteOutcome {
            $error = $errors[$id] ?? null;

            return match (true) {
                $error === null => WriteOutcome::applied($id),
                str_starts_with($error, self::STALE) => WriteOutcome::stale($id),
                default => WriteOutcome::failed($id, Assert::truncate($error, self::MAX_REASON)),
            };
        }, $ids);
    }
}
