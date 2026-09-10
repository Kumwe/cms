<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Http\Message\StreamInterface;
use stdClass;

/**
 * Driver-neutral application seam exposed to Studio JSON and binary delivery adapters.
 *
 * @since  2.0.0
 */
interface StudioMediaOperations
{
    /**
     * Authorize one validated upload descriptor before bytes move.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App context.
     * @param   StudioHostSessionSnapshot  $snapshot  Current Studio authority.
     * @param   StudioMediaUploadRequest   $request   Validated upload descriptor.
     *
     * @return  stdClass  Canonical upload grant.
     *
     * @since   2.0.0
     */
    public function authorizeUpload(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        StudioMediaUploadRequest $request,
    ): stdClass;

    /**
     * Restore the live capability removed from a durable authorize-upload replay.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App context.
     * @param   StudioHostSessionSnapshot  $snapshot  Current Studio authority.
     * @param   stdClass                   $stored    Integrity-checked secret-free grant.
     *
     * @return  stdClass  Exact grant with its verified deterministic token restored.
     *
     * @since   2.0.0
     */
    public function replayUploadGrant(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $stored,
    ): stdClass;

    /**
     * Receive one binary upload under its exact grant scope.
     *
     * @param   ExecutionContext  $context     Fresh authenticated App context.
     * @param   string            $uploadId    Opaque upload identity.
     * @param   string            $contextKey  Opaque resource-context header.
     * @param   string            $generation  Authority-generation header.
     * @param   string            $token       Single-purpose grant token.
     * @param   string            $mediaType   Transfer content type.
     * @param   StreamInterface   $body        Streaming request body.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function receive(
        ExecutionContext $context,
        string $uploadId,
        string $contextKey,
        string $generation,
        string $token,
        string $mediaType,
        StreamInterface $body,
    ): void;

    /**
     * Cancel an active upload without deleting accepted media.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App context.
     * @param   StudioHostSessionSnapshot  $snapshot  Current Studio authority.
     * @param   string                     $uploadId  Opaque upload identity.
     *
     * @return  null
     *
     * @since   2.0.0
     */
    public function abortUpload(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $uploadId,
    ): null;

    /**
     * Verify and accept one completely transferred upload.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App context.
     * @param   StudioHostSessionSnapshot  $snapshot  Current Studio authority.
     * @param   string                     $uploadId  Opaque upload identity.
     *
     * @return  stdClass  Small accepted-asset identity.
     *
     * @since   2.0.0
     */
    public function completeUpload(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $uploadId,
    ): stdClass;

    /**
     * Resolve one media asset, with absence represented as null.
     *
     * @param   ExecutionContext  $context  Fresh authenticated App context.
     * @param   string            $assetId  Media asset identity.
     *
     * @return  stdClass|null  Canonical asset or null.
     *
     * @since   2.0.0
     */
    public function get(ExecutionContext $context, string $assetId): ?stdClass;

    /**
     * Return one bounded canonical media page.
     *
     * @param   ExecutionContext  $context  Fresh authenticated App context.
     * @param   stdClass          $query    Canonical media query.
     *
     * @return  stdClass  Canonical media page.
     *
     * @since   2.0.0
     */
    public function list(ExecutionContext $context, stdClass $query): stdClass;

    /**
     * Harden and import one external candidate.
     *
     * @param   ExecutionContext  $context  Fresh authenticated App context.
     * @param   string            $url      Untrusted external candidate.
     *
     * @return  stdClass  Small accepted-asset identity.
     *
     * @since   2.0.0
     */
    public function importExternal(ExecutionContext $context, string $url): stdClass;

    /**
     * Poll one previously accepted asset.
     *
     * @param   ExecutionContext  $context  Fresh authenticated App context.
     * @param   string            $assetId  Media asset identity.
     *
     * @return  stdClass  Small accepted-asset identity.
     *
     * @since   2.0.0
     */
    public function uploadStatus(ExecutionContext $context, string $assetId): stdClass;
}
