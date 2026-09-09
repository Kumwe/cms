<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use stdClass;

/**
 * Canonical Studio document fragments the contextual Content authoring host emits.
 *
 * Every identifier, reference and message this class mints is deterministic: the same App
 * coordinates always project to the same Studio wire value, so the deployment document, the
 * resolve-target resolution, the start snapshot and every save result agree byte for byte, which is
 * what the Studio shell requires before it trusts a session.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioAuthoringDocuments
{
    /**
     * Contract version every emitted Studio document declares.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string CONTRACT_VERSION = '0.1-draft';

    /**
     * Qualified resource type of a Content entry inside a Studio resource context.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string RESOURCE_TYPE = 'kumwe.app/content-entry';

    /**
     * Qualified scope kind naming the site that owns an authoring context.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string SITE_SCOPE = 'kumwe.app/site';

    /**
     * Prefix of every reusable-content-type identifier projected from a Content type.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string TYPE_ID_PREFIX = 'content-type:';

    /**
     * Owner reference every App-authored Studio document carries.
     *
     * @var    array{id: string, version: string}
     * @since  2.0.0
     */
    public const array OWNER = ['id' => 'kumwe.app/content', 'version' => '2.0.0'];

    /**
     * Not constructable: every member is a pure projection.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }

    /**
     * The immutable core Content authoring target declaration.
     *
     * @param   list<stdClass>  $contributionDependencies  Exact App-owned contributions the target requires.
     *
     * @return  stdClass  Schema-valid `authoring-target` declaration.
     *
     * @since   2.0.0
     */
    public static function declaration(array $contributionDependencies = []): stdClass
    {
        return (object) [
            'contractVersion' => self::CONTRACT_VERSION,
            'kind' => 'authoring-target',
            'id' => ContentStudioAuthoringTarget::TARGET_ID,
            'owner' => (object) self::OWNER,
            'label' => self::message('kumwe.app/content-authoring', 'Content'),
            'surface' => ContentStudioAuthoringTarget::SURFACE,
            'resourceTypes' => [self::RESOURCE_TYPE],
            'eligibility' => ['create', 'edit'],
            'modes' => ['model', 'blueprint', 'content'],
            'startKinds' => ['blank', 'from-type', 'existing'],
            'presentationStates' => ['inline', 'minimized', 'maximized', 'fullscreen'],
            'saveOutcomes' => ['save-item', 'save-new-type-version', 'save-as-new-type'],
            'requiredCapabilities' => [],
            'contributionDependencies' => $contributionDependencies,
        ];
    }

    /**
     * The resource context Studio carries for one host session and its trusted target.
     *
     * The context always names a resource: the stored entry while editing, or the session's
     * provisional entry identity while creating, because Studio reproduces the authoring target from
     * the resource type before it opens a session and a create session keeps the same context for
     * its whole life even after its first save persists the entry.
     *
     * @param   StudioHostSession             $session  Opened contextual host session.
     * @param   ContentStudioAuthoringTarget  $target   PHP-resolved Content target.
     *
     * @return  stdClass  Schema-valid common `resourceContext`.
     *
     * @since   2.0.0
     */
    public static function resourceContext(StudioHostSession $session, ContentStudioAuthoringTarget $target): stdClass
    {
        return (object) [
            'key' => $session->resourceContextKey,
            'surface' => ContentStudioAuthoringTarget::SURFACE,
            'scopes' => [(object) ['kind' => self::SITE_SCOPE, 'id' => 'sites/' . $session->siteId]],
            'resource' => (object) [
                'type' => self::RESOURCE_TYPE,
                'id' => $target->entryId ?? self::draftEntryId($session->resourceContextKey),
            ],
        ];
    }

    /**
     * The stable Studio session identifier derived from one opaque host context key.
     *
     * @param   string  $resourceContextKey  Opaque host-session key.
     *
     * @return  string  Stable identifier carrying no key material.
     *
     * @since   2.0.0
     */
    public static function sessionId(string $resourceContextKey): string
    {
        return 'sessions/' . substr(hash('sha256', 'kumwe-content-authoring-session:' . $resourceContextKey), 0, 40);
    }

    /**
     * The host-minted return pointer for one session, or a successor pointer for one accepted save.
     *
     * @param   string  $resourceContextKey  Opaque host-session key.
     * @param   string  $discriminator       Empty for the session itself, or the accepted save's fingerprint.
     *
     * @return  stdClass  Schema-valid common `returnContext`.
     *
     * @since   2.0.0
     */
    public static function returnContext(string $resourceContextKey, string $discriminator = ''): stdClass
    {
        return (object) [
            'key' => 'returns/' . substr(
                hash('sha256', 'kumwe-content-authoring-return:' . $resourceContextKey . "\n" . $discriminator),
                0,
                40,
            ),
            'label' => self::message('kumwe.app/return-to-content', 'Return to the content editor'),
        ];
    }

    /**
     * The entry identity a create session promises before its first save persists the item.
     *
     * The identity is a UUID derived from the opaque host-session key, and the first `save-item` of
     * that session persists the entry under exactly this UUID. A session's resource context therefore
     * names the same resource from launch through every later save, which is what Studio requires of
     * a reconciled session, while two sessions never promise the same identity.
     *
     * @param   string  $resourceContextKey  Opaque host-session key.
     *
     * @return  string  `content-entry:<uuid>` reversible by {@see ContentStudioProjector::contentEntryId()}.
     *
     * @since   2.0.0
     */
    public static function draftEntryId(string $resourceContextKey): string
    {
        $digest = hash('sha256', 'kumwe-draft-entry:' . $resourceContextKey);
        $uuid = sprintf(
            '%s-%s-4%s-%x%s-%s',
            substr($digest, 0, 8),
            substr($digest, 8, 4),
            substr($digest, 13, 3),
            8 | (hexdec($digest[16]) & 0x3),
            substr($digest, 17, 3),
            substr($digest, 20, 12),
        );

        return 'content-entry:' . $uuid;
    }

    /**
     * The provisional model identity of a blank canvas inside one session.
     *
     * @param   string  $resourceContextKey  Opaque host-session key.
     *
     * @return  string  Stable identifier outside the persisted `content-model:<uuid>` grammar.
     *
     * @since   2.0.0
     */
    public static function draftModelId(string $resourceContextKey): string
    {
        return 'content-model:draft/' . substr(hash('sha256', 'kumwe-draft-model:' . $resourceContextKey), 0, 32);
    }

    /**
     * The provisional Blueprint identity of a blank canvas inside one session.
     *
     * @param   string  $resourceContextKey  Opaque host-session key.
     *
     * @return  string  Stable identifier outside the persisted Blueprint grammar.
     *
     * @since   2.0.0
     */
    public static function draftBlueprintId(string $resourceContextKey): string
    {
        $digest = hash('sha256', 'kumwe-draft-blueprint:' . $resourceContextKey);

        return 'content-blueprint:draft/' . substr($digest, 0, 32);
    }

    /**
     * The reusable-content-type identifier of one Content type.
     *
     * @param   string  $contentTypeId  Canonical Content type UUID.
     *
     * @return  string  Stable identifier, reversible by {@see contentTypeId()}.
     *
     * @since   2.0.0
     */
    public static function typeId(string $contentTypeId): string
    {
        return self::TYPE_ID_PREFIX . strtolower($contentTypeId);
    }

    /**
     * Decode the Content type UUID from a reusable-content-type identifier.
     *
     * @param   string  $typeId  Studio stable identifier.
     *
     * @return  ?string  Lowercase UUID, or null when the identifier is not a Content type projection.
     *
     * @since   2.0.0
     */
    public static function contentTypeId(string $typeId): ?string
    {
        if (!str_starts_with($typeId, self::TYPE_ID_PREFIX)) {
            return null;
        }

        return ContentStudioProjector::contentTypeId(
            'content-model:' . substr($typeId, strlen(self::TYPE_ID_PREFIX)),
        );
    }

    /**
     * The locked reference of one Content type as a reusable content type.
     *
     * @param   ContentTypeDefinition  $definition  Exact published definition version.
     *
     * @return  stdClass  `{id, version, revision}` reference.
     *
     * @since   2.0.0
     */
    public static function typeReference(ContentTypeDefinition $definition): stdClass
    {
        return (object) [
            'id' => self::typeId($definition->id),
            'version' => ContentStudioProjector::modelVersion($definition->version),
            'revision' => ContentStudioProjector::modelRevision($definition->version),
        ];
    }

    /**
     * The locked reference of one Content type's projected Studio model.
     *
     * @param   ContentTypeDefinition  $definition  Exact published definition version.
     *
     * @return  stdClass  `{id, version, revision}` reference.
     *
     * @since   2.0.0
     */
    public static function modelReference(ContentTypeDefinition $definition): stdClass
    {
        return (object) [
            'id' => ContentStudioProjector::modelId($definition->id),
            'version' => ContentStudioProjector::modelVersion($definition->version),
            'revision' => ContentStudioProjector::modelRevision($definition->version),
        ];
    }

    /**
     * The reusable-content-type definition of one Content type bound to one exact Blueprint revision.
     *
     * @param   ContentTypeDefinition  $definition  Exact published definition version.
     * @param   stdClass               $blueprint   Locked Blueprint reference.
     *
     * @return  stdClass  Schema-valid `reusable-content-type` document.
     *
     * @since   2.0.0
     */
    public static function typeDefinition(ContentTypeDefinition $definition, stdClass $blueprint): stdClass
    {
        return (object) [
            'contractVersion' => self::CONTRACT_VERSION,
            'kind' => 'reusable-content-type',
            'id' => self::typeId($definition->id),
            'version' => ContentStudioProjector::modelVersion($definition->version),
            'revision' => ContentStudioProjector::modelRevision($definition->version),
            'label' => self::message(
                'kumwe.content/type-' . substr(hash('sha256', $definition->handle), 0, 32),
                $definition->name,
            ),
            'status' => 'published',
            'model' => self::modelReference($definition),
            'blueprint' => $blueprint,
            'authoringPolicy' => self::authoringPolicy(),
        ];
    }

    /**
     * The one authoring policy every Kumwe content type declares: every mode, no item-local Blueprint.
     *
     * @return  stdClass  Schema-valid `authoringPolicy`.
     *
     * @since   2.0.0
     */
    public static function authoringPolicy(): stdClass
    {
        return (object) [
            'modes' => ['model', 'blueprint', 'content'],
            'itemComposition' => 'denied',
        ];
    }

    /**
     * One bounded message reference.
     *
     * @param   string  $key      Qualified message key.
     * @param   string  $default  Human default, truncated to the contract bound.
     *
     * @return  stdClass  Schema-valid `messageReference`.
     *
     * @since   2.0.0
     */
    public static function message(string $key, string $default): stdClass
    {
        $default = trim($default) === '' ? 'Untitled' : trim($default);

        return (object) ['key' => $key, 'defaultMessage' => mb_substr($default, 0, 500)];
    }

    /**
     * One bounded diagnostic.
     *
     * @param   string                                     $code        Qualified diagnostic code.
     * @param   string                                     $severity    `information`, `warning`, `error` or `blocking`.
     * @param   string                                     $default     Human default message.
     * @param   array<string, string|int|float|bool|null>  $parameters  Optional bounded scalar parameters.
     *
     * @return  stdClass  Schema-valid common `diagnostic`.
     *
     * @since   2.0.0
     */
    public static function diagnostic(string $code, string $severity, string $default, array $parameters = []): stdClass
    {
        $diagnostic = (object) [
            'code' => $code,
            'severity' => $severity,
            'message' => self::message($code, $default),
        ];
        if ($parameters !== []) {
            $diagnostic->parameters = (object) $parameters;
        }

        return $diagnostic;
    }
}
