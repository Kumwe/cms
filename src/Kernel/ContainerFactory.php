<?php

declare(strict_types=1);

namespace Kumwe\App\Kernel;

use Kumwe\CanonicalJson\CanonicalEncoder;
use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Automation\AutomationManagementService;
use Kumwe\App\Application\Automation\CryptographicJitterSource;
use Kumwe\App\Infrastructure\Automation\DoctrineJobQueue;
use Kumwe\App\Infrastructure\Automation\DoctrineQueueRuntimeOperations;
use Kumwe\App\Infrastructure\Automation\DoctrineScheduler;
use Kumwe\App\Application\Automation\Job\EnforceAuditRetentionHandler;
use Kumwe\App\Application\Automation\Job\PurgeAdministratorSessionsHandler;
use Kumwe\App\Application\Automation\Job\PurgeBusinessRecordIdempotencyHandler;
use Kumwe\App\Application\Automation\Job\PurgeIdempotencyRecordsHandler;
use Kumwe\App\Application\Automation\Job\PurgeStudioContentAuthoringContextsHandler;
use Kumwe\App\Application\Automation\Job\RecordAuditAnchorHandler;
use Kumwe\App\Application\Automation\Job\RotateRecordSecretsHandler;
use Kumwe\App\Application\Automation\Job\RebuildExtensionMapHandler;
use Kumwe\App\Application\Automation\Job\SynchronizeTrustRevocationsHandler;
use Kumwe\App\Application\Automation\Job\VerifyAuditTrailHandler;
use Kumwe\App\Application\Automation\Job\ScheduleRepository;
use Kumwe\App\Application\Automation\Job\TransitionContentHandler;
use Kumwe\App\Application\Automation\JobHandlerRegistry;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Application\Automation\GlobalJobPrincipals;
use Kumwe\App\Application\Automation\JobExecutionScope;
use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\App\Application\Automation\QueueRuntimeOperations;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\App\Application\Automation\JitterSource;
use Kumwe\App\Application\Automation\RetryPolicy;
use Kumwe\App\Application\Automation\IdempotencyPurger;
use Kumwe\App\Application\Idempotency\IdempotencyLedger;
use Kumwe\App\Application\Idempotency\SecretOnceIdempotencyLedger;
use Kumwe\App\Application\Automation\Scheduler;
use Kumwe\App\Application\Automation\Worker;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\App\Application\Authorization\CompositeResourceOwnershipReferences;
use Kumwe\App\Application\Authorization\DenyByDefaultAuthorizationGateway;
use Kumwe\App\Application\Authorization\MembershipContextValidator;
use Kumwe\App\Application\Authorization\ResourceOwnershipReferences;
use Kumwe\App\Application\Authorization\ResourceOwnershipScopePolicy;
use Kumwe\App\Application\Authorization\ResourceOwnershipScopeService;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SiteGroupAdministration;
use Kumwe\App\Application\Authorization\SiteGroupRegistry;
use Kumwe\App\Application\Authorization\SiteGroupWriter;
use Kumwe\App\Application\Authorization\StructuredLogAuthorizationDecisionRecorder;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Application\Persistence\TransactionState;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroupRepository;
use Kumwe\App\Application\Security\HighImpactCredentialGuard;
use Kumwe\App\Application\Operations\ExpiredMigrationLockRecovery;
use Kumwe\App\Application\Operations\MigrationLockRecoveryService;
use Kumwe\App\Administrator\Content\ContentFormDataMapper;
use Kumwe\App\Administrator\Content\ContentFormPresenter;
use Kumwe\App\Administrator\Content\ContentModelFormMapper;
use Kumwe\App\Administrator\Content\ContentModelFormPresenter;
use Kumwe\App\Administrator\Automation\AutomationJobFormRegistry;
use Kumwe\App\Administrator\Automation\ContributedJobFormCompiler;
use Kumwe\App\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\App\Administrator\Http\Handler\AdministratorContentEditorHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorContentListHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorContentModelsHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorAccessControlHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorBusinessSecurityHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorAutomationHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorCreateContentHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorDashboardHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorDashboardPreferencesHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorExtensionActionHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorExtensionsHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorInterfaceStandardHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorLoginHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorLogoutHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorMediaHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorNavigationHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorRestoreContentHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorSettingsHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorStudioHostHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorStudioPreviewDocumentHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorStudioPreviewStylesheetHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorStudioMediaUploadHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorStudioCompositionHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorStudioSessionHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorTransitionContentHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorTrashContentHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorWordingHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorUpdateContentHandler;
use Kumwe\App\Administrator\Http\Middleware\AdministratorCsrfMiddleware;
use Kumwe\App\Administrator\Http\Middleware\AdministratorAuthorizationMiddleware;
use Kumwe\App\Administrator\Http\Middleware\AdministratorSessionMiddleware;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\App\Administrator\Presentation\SitePresentationFormMapper;
use Kumwe\App\Audit\Application\AuditAnchorWriter;
use Kumwe\App\Audit\Application\AuditArchiveStorage;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Application\AuditRetentionService;
use Kumwe\App\Audit\Application\AuditTrailExporter;
use Kumwe\App\Audit\Application\AuditTrailVerifier;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditAnchorWriter;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditRecorder;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditRetentionService;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditTrailExporter;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditTrailVerifier;
use Kumwe\App\Audit\Infrastructure\Storage\FilesystemAuditArchiveStorage;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionCompatibilityAnalyzer;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionContractAdmission;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Application\PackageDefinitionSynchronizer;
use Kumwe\App\BusinessDefinition\Administrator\BusinessDefinitionFormMapper;
use Kumwe\App\BusinessDefinition\Delivery\Api\BusinessDefinitionApiHandler;
use Kumwe\App\BusinessDefinition\Delivery\Api\BusinessDefinitionApiPresenter;
use Kumwe\App\BusinessDefinition\Delivery\Administrator\BusinessDefinitionsHandler;
use Kumwe\App\BusinessDefinition\Infrastructure\Persistence\DoctrineBusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Infrastructure\Persistence\DoctrinePackageDefinitionSynchronizer;
use Kumwe\App\BusinessDefinition\Infrastructure\Persistence\DoctrinePersistedFieldTypeDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyPurger;
use Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyRepository;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationPublication;
use Kumwe\App\BusinessRecord\Application\DocumentCommitTimingRecorder;
use Kumwe\App\BusinessRecord\Application\BusinessRecordReadRepository;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRelationshipCoordinator;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRevisionRepository;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\BusinessRecordWriteRepository;
use Kumwe\App\BusinessRecord\Application\InstalledBusinessRecordDefinitionResolver;
use Kumwe\Conversion\Provider\MoneyConversionPipeline;
use Kumwe\Conversion\Provider\MoneyRateProviderCatalog;
use Kumwe\App\BusinessRecord\Application\PolicyBusinessRecordReader;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordReader;
use Kumwe\App\BusinessRecord\Application\PostingPeriodCalendar;
use Kumwe\App\BusinessRecord\Application\PostingPeriodLock;
use Kumwe\App\BusinessRecord\Application\PostingPeriodRepository;
use Kumwe\App\BusinessRecord\Application\PostingPeriodService;
use Kumwe\Conversion\Contract\MoneyConverter;
use Kumwe\Conversion\Provider\UnitConversionPipeline;
use Kumwe\Conversion\Provider\UnitConversionProviderCatalog;
use Kumwe\Conversion\Contract\QuantityConverter;
use Kumwe\App\BusinessRecord\Infrastructure\RuntimeMoneyRateProviderCatalog;
use Kumwe\App\BusinessRecord\Infrastructure\RuntimeUnitConversionProviderCatalog;
use Kumwe\App\BusinessRecord\Application\RecordCursorCodec;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\App\BusinessRecord\Application\RecordRuleValidator;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessRecord\Application\RecordSecretRotation;
use Kumwe\App\BusinessRecord\Application\SecretCipher;
use Kumwe\App\BusinessRecord\Application\SecretKeyProvider;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessNumberSequenceAllocator;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordIdempotencyRepository;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordQueryCompiler;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordReadRepository;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordRevisionRepository;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordWriteRepository;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessSchemaRecordRepinGateway;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrinePostingPeriodRepository;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineRecordSecretRotation;
use Kumwe\App\BusinessRecord\Infrastructure\Security\ConfiguredSecretKeyRings;
use Kumwe\App\BusinessRecord\Infrastructure\Security\KeyRingSecretCipher;
use Kumwe\App\BusinessRecord\Infrastructure\Security\KeyRingSecretKeyProvider;
use Kumwe\App\BusinessIntegration\Application\BusinessRecordMutationEventPublisher;
use Kumwe\App\BusinessIntegration\Application\DurableOutboundAdapterDispatcher;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\InboxStore;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventConsumerDispatcher;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventFanout;
use Kumwe\App\BusinessIntegration\Application\IntegrationOperationsService;
use Kumwe\App\BusinessIntegration\Application\JobQueueProcessWorkHandler;
use Kumwe\App\BusinessIntegration\Application\OutboxDispatcher;
use Kumwe\App\BusinessIntegration\Application\OutboxStore;
use Kumwe\App\BusinessIntegration\Application\ScheduleRuntimeSynchronizer;
use Kumwe\App\BusinessIntegration\Application\ProcessManagerService;
use Kumwe\App\BusinessIntegration\Application\ProcessManagerStore;
use Kumwe\App\BusinessIntegration\Application\ProcessWorkDispatcher;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\App\BusinessIntegration\Application\ValidatedContributedJobHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\Extension\Spi\Application\Automation\JobHandler as ContributedJobHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineInboxStore;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineOutboxStore;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineProcessManagerStore;
use Kumwe\App\BusinessIntegration\Infrastructure\ContributedScheduleSynchronizer;
use Kumwe\App\BusinessIntegration\Infrastructure\ContributedQueueRuntimePolicyCatalog;
use Kumwe\App\BusinessIntegration\Infrastructure\ExtensionRuntimeGenerationGuard;
use Kumwe\App\BusinessIntegration\Infrastructure\RuntimeIntegrationEventTransport;
use Kumwe\App\BusinessReporting\Application\ConsolidatedGroupReportScope;
use Kumwe\App\BusinessReporting\Application\ExportArtifactRepository;
use Kumwe\App\BusinessReporting\Application\ExportArtifactStorage;
use Kumwe\App\BusinessReporting\Application\ExportExecutionContextResolver;
use Kumwe\App\BusinessReporting\Application\ExportGenerationService;
use Kumwe\App\BusinessReporting\Application\ExportJobDispatcher;
use Kumwe\App\BusinessReporting\Application\ExportPolicySnapshotProvider;
use Kumwe\App\BusinessReporting\Application\ExportQueueProducerContextProvider;
use Kumwe\App\BusinessReporting\Application\ExportService;
use Kumwe\App\BusinessReporting\Application\GenerateReportExportHandler;
use Kumwe\App\BusinessReporting\Application\ProjectionRuntime;
use Kumwe\App\BusinessReporting\Application\RecordExportReportProvider;
use Kumwe\App\BusinessReporting\Application\ReportCsvEncoder;
use Kumwe\App\BusinessReporting\Application\ReportDefinitionRegistry;
use Kumwe\App\BusinessReporting\Application\ReportService;
use Kumwe\App\BusinessReporting\Application\ReportScopeResolver;
use Kumwe\App\BusinessReporting\Delivery\Administrator\AdministratorReportHandler;
use Kumwe\App\BusinessReporting\Delivery\Api\ReportApiHandler;
use Kumwe\App\BusinessReporting\Delivery\Api\ReportApiPresenter;
use Kumwe\App\BusinessReporting\Delivery\Console\ReportCommand;
use Kumwe\App\BusinessReporting\Delivery\Portal\PortalReportHandler;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\App\BusinessReporting\Infrastructure\BusinessRecordExportPolicySnapshotProvider;
use Kumwe\App\BusinessReporting\Infrastructure\BusinessRecordReportScopeResolver;
use Kumwe\App\BusinessReporting\Infrastructure\BusinessRecordServiceReportReader;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineExportArtifactRepository;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineProjectionRuntime;
use Kumwe\App\BusinessReporting\Infrastructure\FilesystemExportArtifactStorage;
use Kumwe\App\BusinessReporting\Infrastructure\JobQueueExportJobDispatcher;
use Kumwe\App\BusinessReporting\Infrastructure\LiveExportExecutionContextResolver;
use Kumwe\App\BusinessReporting\Infrastructure\SystemExportQueueProducerContextProvider;
use Kumwe\App\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\App\BusinessSurface\Application\BusinessMutationPlanService;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusRepository;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusService;
use Kumwe\App\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\App\BusinessSurface\Application\BusinessRecordQueryFactory;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\BusinessSurface\Application\CustomBusinessActionExecutor;
use Kumwe\App\BusinessSurface\Application\FieldModelPresenter;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessSurfaceDispatcher;
use Kumwe\App\BusinessSurface\Application\GeneratedBusinessActionStepUp;
use Kumwe\App\BusinessSurface\Application\MutationPlanCipher;
use Kumwe\App\BusinessSurface\Delivery\Administrator\AdministratorBusinessSurfaceHandler;
use Kumwe\App\BusinessSurface\Delivery\Browser\BusinessCustomViewPresenter;
use Kumwe\App\BusinessSurface\Delivery\Browser\BusinessFormInputMapper;
use Kumwe\App\BusinessSurface\Delivery\Browser\BusinessDocumentPresenter;
use Kumwe\App\BusinessSurface\Delivery\Browser\GeneratedBusinessBrowserController;
use Kumwe\App\BusinessSurface\Delivery\Portal\GeneratedBusinessPortalNavigationVisibility;
use Kumwe\App\BusinessSurface\Delivery\Portal\PortalBusinessSurfaceHandler;
use Kumwe\App\BusinessSurface\Infrastructure\Persistence\DoctrineBusinessOperationStatusRepository;
use Kumwe\App\BusinessSurface\Infrastructure\Security\KeyRingMutationPlanCipher;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationRegistry;
use Kumwe\App\BusinessSurface\Presentation\Field\RegistryFieldModelPresenter;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRepository;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalQueryRepository;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalQueryService;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalService;
use Kumwe\App\BusinessSecurity\Application\Approval\StepUpProofConsumer;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationRepository;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineApprovalRepository;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineApprovalQueryRepository;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineBusinessRecordAccessController;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineBusinessSecurityAdministrationRepository;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineMembershipDirectory;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineStepUpProofConsumer;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaEnvironment;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaExecutionLock;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaExecutionStateGuard;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaExecutor;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaLifecycleManager;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaLifecycleObserver;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaPlanner;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaPlanRepository;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaRecordRepinGateway;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaRecoveryEvidenceRepository;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Application\DefinitionPhysicalSchemaCompiler;
use Kumwe\App\BusinessSchema\Application\PhysicalSchemaGateway;
use Kumwe\App\BusinessSchema\Application\PublishedDefinitionSchemaObserver;
use Kumwe\App\BusinessSchema\Delivery\Api\BusinessSchemaApiHandler;
use Kumwe\App\BusinessSchema\Delivery\Api\BusinessSchemaApiPresenter;
use Kumwe\App\Delivery\Http\Api\Business\BusinessApiResponder;
use Kumwe\App\Delivery\Http\Api\Business\BusinessApprovalApiHandler;
use Kumwe\App\Delivery\Http\Api\Business\BusinessApprovalApiPresenter;
use Kumwe\App\Delivery\Http\Api\Business\BusinessDefinitionDiscoveryApiHandler;
use Kumwe\App\Delivery\Http\Api\Business\BusinessOperationStatusApiHandler;
use Kumwe\App\Delivery\Http\Api\Business\BusinessRecordApiHandler;
use Kumwe\App\Delivery\Http\Api\Business\BusinessRecordApiPresenter;
use Kumwe\App\Delivery\Http\Api\Business\BusinessRecordApiResponder;
use Kumwe\App\Delivery\Http\Api\Business\PostingPeriodApiHandler;
use Kumwe\App\BusinessSchema\Delivery\Administrator\ApproveBusinessSchemaPlanHandler;
use Kumwe\App\BusinessSchema\Delivery\Administrator\BusinessSchemaPlansHandler;
use Kumwe\App\BusinessSchema\Delivery\Administrator\CreateBusinessSchemaPlanHandler;
use Kumwe\App\BusinessSchema\Delivery\Administrator\CreateBusinessSchemaPurgePlanHandler;
use Kumwe\App\BusinessSchema\Delivery\Administrator\ExecuteBusinessSchemaPlanHandler;
use Kumwe\App\BusinessSchema\Delivery\Administrator\RecordBusinessSchemaRecoveryEvidenceHandler;
use Kumwe\App\BusinessSchema\Delivery\Administrator\RecoverBusinessSchemaPlanHandler;
use Kumwe\App\BusinessSchema\Domain\PhysicalNameCompiler;
use Kumwe\App\BusinessSchema\Infrastructure\Execution\ConfiguredBusinessSchemaEnvironment;
use Kumwe\App\BusinessSchema\Infrastructure\Execution\DoctrineBusinessSchemaExecutionLock;
use Kumwe\App\BusinessSchema\Infrastructure\Execution\DoctrineBusinessSchemaExecutionStateGuard;
use Kumwe\App\BusinessSchema\Infrastructure\Persistence\DoctrineBusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Infrastructure\Persistence\DoctrineBusinessSchemaPlanRepository;
use Kumwe\App\BusinessSchema\Infrastructure\Persistence\DoctrineBusinessSchemaRecoveryEvidenceRepository;
use Kumwe\App\BusinessSchema\Infrastructure\Schema\CanonicalDefinitionPhysicalSchemaCompiler;
use Kumwe\App\BusinessSchema\Infrastructure\Schema\DoctrinePhysicalSchemaGateway;
use Kumwe\App\Content\Application\ContentRepository;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Application\TranslationGroupRepository;
use Kumwe\App\Content\Domain\JsonSchemaValidator;
use Kumwe\App\Content\Domain\SchemaCompatibilityChecker;
use Kumwe\App\Content\Infrastructure\Persistence\DoctrineContentModelRepository;
use Kumwe\App\Content\Infrastructure\Persistence\DoctrineContentRepository;
use Kumwe\App\Content\Infrastructure\Persistence\DoctrineTranslationGroupRepository;
use Kumwe\App\Content\Presentation\TranslationGroupPresenter;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringCatalog;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringLaunchResolver;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextAuthority;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextPurger;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextRepository;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringService;
use Kumwe\App\Studio\Application\Authoring\HostedContentStudioAuthoringConfigurationProvider;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringAvailability;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringConfigurationProvider;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\ContentStudioResourceSearchProvider;
use Kumwe\App\Studio\Application\Projection\RecordAuthorizedStudioContentFieldDisclosure;
use Kumwe\App\Studio\Application\Projection\StudioContentFieldDisclosure;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Studio\Application\Composition\ContentBlueprintBindingStore;
use Kumwe\App\Studio\Application\Composition\CanonicalStudioPublishedContentRenderer;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Composition\StudioBuiltInThemeRelease;
use Kumwe\App\Studio\Application\Composition\StudioPublishedCompositionGuard;
use Kumwe\App\Studio\Application\Composition\StudioPublishedContentRenderer;
use Kumwe\App\Studio\Application\Composition\StudioPublishedEnhancementRuntime;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioLocalizationHostPort;
use Kumwe\App\Studio\Application\Host\StudioModelHostPort;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactHostPort;
use Kumwe\App\Studio\Application\Host\StudioArtifactPublicationGuard;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Host\StudioMutationOutcomeCodec;
use Kumwe\App\Studio\Application\Host\StudioMutationReplayRepository;
use Kumwe\App\Studio\Application\Host\StudioAuthoringHostPort;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Studio\Application\Host\StudioRecoveryHostPort;
use Kumwe\App\Studio\Application\Host\StudioRecoveryRepository;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Host\StudioResourceHostPort;
use Kumwe\App\Studio\Presentation\Preview\CanonicalStudioPreviewRenderer;
use Kumwe\App\Studio\Application\Preview\ContentStudioPreviewBindingSource;
use Kumwe\App\Studio\Application\Preview\StudioPreviewActivityRecorder;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResolver;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingSource;
use Kumwe\App\Studio\Application\Preview\StudioPreviewDraftSource;
use Kumwe\App\Studio\Application\Preview\StudioPreviewGrantRepository;
use Kumwe\App\Studio\Application\Preview\StudioPreviewHostPort;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRenderer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewSequenceRepository;
use Kumwe\App\Studio\Application\Preview\StudioPreviewTransportGuard;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\App\Studio\Application\Release\StudioCoreCatalog;
use Kumwe\App\Studio\Application\Release\StudioReleaseRecord;
use Kumwe\App\Studio\Application\Media\StudioExternalAddressResolver;
use Kumwe\App\Studio\Application\Media\StudioExternalMediaFetcher;
use Kumwe\App\Studio\Application\Media\StudioMediaAssetProjector;
use Kumwe\App\Studio\Application\Media\StudioMediaCursorCodec;
use Kumwe\App\Studio\Application\Media\StudioMediaGrantToken;
use Kumwe\App\Studio\Application\Media\StudioMediaHostPort;
use Kumwe\App\Studio\Application\Media\StudioMediaOperations;
use Kumwe\App\Studio\Application\Media\StudioMediaService;
use Kumwe\App\Studio\Application\Media\StudioMediaSignatureVerifier;
use Kumwe\App\Studio\Application\Media\StudioMediaStagingStorage;
use Kumwe\App\Studio\Application\Media\StudioMediaUploadRepository;
use Kumwe\App\Studio\Application\Media\StudioPinnedHttpTransport;
use Kumwe\App\Studio\Application\Host\StudioTelemetryHostPort;
use Kumwe\Sequence\Contract\NumberSequenceAllocator;
use Kumwe\Producer\Deployment\StudioBrowserAssetLocator;
use Kumwe\Producer\Deployment\StudioDeploymentEmitter;
use Kumwe\Producer\Schema\StudioContractResources;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\App\Studio\Domain\Media\StudioExternalUrlPolicy;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadPolicy;
use Kumwe\App\Studio\Infrastructure\Host\RandomStudioResourceContextKeyFactory;
use Kumwe\App\Studio\Infrastructure\Host\SodiumStudioMutationOutcomeCodec;
use Kumwe\App\Studio\Infrastructure\Observability\StructuredLogStudioPreviewActivityRecorder;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineContentProjectionBindingRepository;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineContentStudioAuthoringContextRepository;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineContentStudioAuthoringContextPurger;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioHostStorage;
use Kumwe\App\Studio\Infrastructure\Media\FilesystemStudioMediaStagingStorage;
use Kumwe\App\Studio\Infrastructure\Media\FinfoStudioMediaSignatureVerifier;
use Kumwe\App\Studio\Infrastructure\Media\NativeStudioExternalAddressResolver;
use Kumwe\App\Studio\Infrastructure\Media\SocketStudioPinnedHttpTransport;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioMediaUploadRepository;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioHostSessionRepository;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioPreviewDraftSource;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioPreviewRepository;
use Kumwe\App\Studio\Infrastructure\Release\PinnedStudioContextualAuthoringAvailability;
use Kumwe\App\Studio\Infrastructure\Release\StudioContextualAuthoringQualification;
use Kumwe\App\Studio\Infrastructure\Transport\NativeStudioPreviewSequenceWaiter;
use Kumwe\App\Demo\Application\DemoProfileLedger;
use Kumwe\App\Demo\Application\DemoProfileReconciler;
use Kumwe\App\Demo\Application\VdmBusinessManifestProjector;
use Kumwe\App\Demo\Application\VdmBusinessOperationGuard;
use Kumwe\App\Demo\Infrastructure\DemoAccessProvisioner;
use Kumwe\App\Demo\Infrastructure\DemoExampleExtensionInstaller;
use Kumwe\App\Demo\Infrastructure\DemoContentProfileInstaller;
use Kumwe\App\Demo\Infrastructure\DemoBusinessProfileExporter;
use Kumwe\App\Demo\Infrastructure\DemoProfileExporter;
use Kumwe\App\Demo\Infrastructure\DemoProfileInstaller;
use Kumwe\App\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\App\Demo\Infrastructure\Persistence\DoctrineDemoProfileLedger;
use Kumwe\App\Demo\Infrastructure\VdmBusinessDemoInstaller;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\ExtensionRuntimeWithdrawal;
use Kumwe\App\Extension\Application\Install\ExtensionInstallReconciler;
use Kumwe\App\Extension\Application\Migration\ExtensionMigrationRunner;
use Kumwe\App\Extension\Application\Package\PackageAdmissionPolicy;
use Kumwe\Extension\Package\ArchiveContentReader;
use Kumwe\App\Extension\Application\Package\ExtensionActivationAdmission;
use Kumwe\Extension\Package\PackageCodeConformance;
use Kumwe\Extension\Package\PackageEvidenceInspector;
use Kumwe\Extension\Package\PublicKeyPackageSignatureVerifier;
use Kumwe\Extension\Package\SodiumPublicKeyPackageSignatureVerifier;
use Kumwe\App\Extension\Application\Trust\ExtensionArtifactVerifier;
use Kumwe\App\Extension\Application\Trust\RevocationFeedSource;
use Kumwe\App\Extension\Application\Trust\RevocationFeedStateStore;
use Kumwe\App\Extension\Application\Trust\RevocationFeedSynchronizer;
use Kumwe\App\Extension\Application\Trust\RevocationListVerifier;
use Kumwe\App\Extension\Application\Trust\TrustRuntimeInvalidator;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\Extension\Package\ZipArchiveContentReader;
use Kumwe\App\Extension\Infrastructure\ExtensionRegistryFenceAllocator;
use Kumwe\App\Extension\Infrastructure\RedisLockedExtensionManager;
use Kumwe\App\Extension\Infrastructure\Trust\DoctrineRevocationFeedStateStore;
use Kumwe\App\Extension\Infrastructure\Trust\DoctrineTrustStoreRepository;
use Kumwe\App\Extension\Infrastructure\Trust\FilesystemExtensionArtifactVerifier;
use Kumwe\App\Extension\Infrastructure\Trust\SodiumRevocationListVerifier;
use Kumwe\App\Extension\Infrastructure\Trust\StreamRevocationFeedSource;
use Kumwe\Extension\Toolchain\ComponentScaffolder;
use Kumwe\Extension\Toolchain\DeterministicPackageBuilder;
use Kumwe\Extension\Toolchain\PackageInspector;
use Kumwe\Extension\Toolchain\PackageSigner;
use Kumwe\Extension\Toolchain\ProtectedSigningKeyReader;
use Kumwe\Extension\Toolchain\StaticConformanceRunner;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Runtime\CurrentExtensionExecutionGate;
use Kumwe\App\Extension\Runtime\DeferredExtensionRuntimeWithdrawal;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\AdministratorViewRegistry;
use Kumwe\Extension\Spi\Contribution\ContributionDefinition;
use Kumwe\App\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\App\Portal\Contribution\PortalTemplateRegistry;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeLoader;
use Kumwe\App\Extension\Runtime\LocalRuntimeReadinessProbe;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\App\Extension\Runtime\RuntimeArtifactDigester;
use Kumwe\App\Extension\Runtime\RuntimeIdentity;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Extension\Runtime\RuntimePublicationKeyRing;
use Kumwe\App\Delivery\Console\Command\CreateAccessTokenCommand;
use Kumwe\App\Delivery\Console\Command\CreateAdministratorCommand;
use Kumwe\App\Delivery\Console\Command\VerifyAuditTrailCommand;
use Kumwe\App\Delivery\Console\Command\DemoAccessCommand;
use Kumwe\App\Delivery\Console\Command\DemoExamplesCommand;
use Kumwe\App\Delivery\Console\Command\DemoExportCommand;
use Kumwe\App\Delivery\Console\Command\DemoInstallCommand;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Command\ActivateExtensionCommand;
use Kumwe\App\Delivery\Console\Command\BuildExtensionCommand;
use Kumwe\App\Delivery\Console\Command\DisableExtensionCommand;
use Kumwe\App\Delivery\Console\Command\ExportAuditTrailCommand;
use Kumwe\App\Delivery\Console\Command\RotateRecordSecretsCommand;
use Kumwe\App\Delivery\Console\Command\HealthCheckCommand;
use Kumwe\App\Delivery\Console\Command\InstallExtensionCommand;
use Kumwe\App\Delivery\Console\Command\InspectExtensionCommand;
use Kumwe\App\Delivery\Console\Command\IntegrationWorkCommand;
use Kumwe\App\Delivery\Console\Command\ListExtensionsCommand;
use Kumwe\App\Delivery\Console\Command\ManageAutomationCommand;
use Kumwe\App\Delivery\Console\Command\ManageIntegrationsCommand;
use Kumwe\App\Delivery\Console\Command\ManageAccessCommand;
use Kumwe\App\Delivery\Console\Command\RecoverCredentialsCommand;
use Kumwe\App\Delivery\Console\Command\ManageContentCommand;
use Kumwe\App\Delivery\Console\Command\ManageBusinessDefinitionsCommand;
use Kumwe\App\Delivery\Console\Command\BusinessConsoleFailureMapper;
use Kumwe\App\Delivery\Console\Command\BusinessRecordConsolePresenter;
use Kumwe\App\Delivery\Console\Command\ManageBusinessRecordsCommand;
use Kumwe\App\Delivery\Console\Command\ManageBusinessSchemaCommand;
use Kumwe\App\Delivery\Console\Command\ManageContentModelsCommand;
use Kumwe\App\Delivery\Console\Command\ManagePostingPeriodsCommand;
use Kumwe\App\Delivery\Console\Command\ManageNavigationCommand;
use Kumwe\App\Delivery\Console\Command\ManageSettingsCommand;
use Kumwe\App\Delivery\Console\Command\ManageTrustStoreCommand;
use Kumwe\App\Delivery\Console\Command\McpServeCommand;
use Kumwe\App\Delivery\Console\Command\MigrateCommand;
use Kumwe\App\Delivery\Console\Command\MaterializeExtensionRuntimeCommand;
use Kumwe\App\Delivery\Console\Command\WatchExtensionRuntimeCommand;
use Kumwe\App\Delivery\Console\Command\MigrationStatusCommand;
use Kumwe\App\Delivery\Console\Command\QueueWorkCommand;
use Kumwe\App\Delivery\Console\Command\RecoverMigrationLockCommand;
use Kumwe\App\Delivery\Console\Command\ScheduleRunCommand;
use Kumwe\App\Delivery\Console\Command\ScaffoldExtensionCommand;
use Kumwe\App\Delivery\Console\Command\SignExtensionCommand;
use Kumwe\App\Delivery\Console\Command\RunExtensionConformanceCommand;
use Kumwe\App\Delivery\Console\Command\UninstallExtensionCommand;
use Kumwe\App\Delivery\Console\Command\RecoverAdministratorThemeCommand;
use Kumwe\App\Delivery\Console\ConsoleApplication;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Delivery\Console\StreamOutput;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Delivery\Http\Api\Idempotency\PersistentIdempotencyMiddleware;
use Kumwe\App\Delivery\Http\Api\Idempotency\HttpMutationPreauthorizer;
use Kumwe\App\Delivery\Http\Api\Idempotency\SecretOnceIdempotencyMiddleware;
use Kumwe\App\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\App\Delivery\Http\Api\Content\ContentApiResponder;
use Kumwe\App\Delivery\Http\Api\Content\ContentCollectionHandler;
use Kumwe\App\Delivery\Http\Api\Content\ContentItemHandler;
use Kumwe\App\Delivery\Http\Api\Content\ContentModelApiHandler;
use Kumwe\App\Delivery\Http\Api\Content\ContentRestoreHandler;
use Kumwe\App\Delivery\Http\Api\Content\ContentTransitionHandler;
use Kumwe\App\Delivery\Http\Api\Extension\ExtensionApiHandler;
use Kumwe\App\Delivery\Http\Api\Extension\TrustStoreApiHandler;
use Kumwe\App\Delivery\Http\Api\Extension\TrustLifecycleMiddleware;
use Kumwe\App\Delivery\Http\Api\Automation\AutomationApiHandler;
use Kumwe\App\Delivery\Http\Api\Identity\AccessControlApiHandler;
use Kumwe\App\Delivery\Http\Api\Navigation\MenuCollectionHandler;
use Kumwe\App\Delivery\Http\Api\Navigation\MenuItemCollectionHandler;
use Kumwe\App\Delivery\Http\Api\Navigation\MenuItemResourceHandler;
use Kumwe\App\Delivery\Http\Api\Navigation\MenuResourceHandler;
use Kumwe\App\Delivery\Http\Api\Navigation\NavigationApiResponder;
use Kumwe\App\Delivery\Http\Api\Plan\PlanPreviewHandler;
use Kumwe\App\Delivery\Http\Api\Plan\SafePlanFactory;
use Kumwe\App\Delivery\Http\Api\Site\SiteSettingsApiHandler;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Delivery\Http\Mcp\McpHttpHandler;
use Kumwe\App\Http\Handler\ApiIndexHandler;
use Kumwe\App\Http\Handler\HomePageHandler;
use Kumwe\App\Http\Handler\ExtensionAssetHandler;
use Kumwe\App\Http\Handler\LivenessHandler;
use Kumwe\App\Http\Handler\MediaAssetHandler;
use Kumwe\App\Http\Handler\MetricsHandler;
use Kumwe\App\Http\Handler\NotFoundHandler;
use Kumwe\App\Http\Handler\PublishedContentHandler;
use Kumwe\App\Http\Handler\StudioPublishedStylesheetHandler;
use Kumwe\App\Http\Handler\ReadinessHandler;
use Kumwe\App\Http\Handler\RobotsHandler;
use Kumwe\App\Http\Middleware\BodyLimitMiddleware;
use Kumwe\App\Http\Middleware\BearerAuthenticationMiddleware;
use Kumwe\App\Http\Middleware\ExtensionRuntimeGenerationMiddleware;
use Kumwe\App\Http\Middleware\MetricsMiddleware;
use Kumwe\App\Http\Middleware\ProblemDetailsMiddleware;
use Kumwe\App\Http\Middleware\RequestIdMiddleware;
use Kumwe\App\Http\Middleware\SecurityHeadersMiddleware;
use Kumwe\App\Http\Middleware\TrustedHostMiddleware;
use Kumwe\App\Http\Middleware\TrustedProxyMiddleware;
use Kumwe\App\Http\Security\TrustedHostMatcher;
use Kumwe\App\Http\Security\TrustedProxyMatcher;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\App\Identity\Application\Administration\TokenDelegationPreauthorizer;
use Kumwe\App\Identity\Application\Administration\TokenRotationPreauthorizer;
use Kumwe\App\Identity\Application\Administration\AccessControlRepository;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AccessTokenQuotaPolicy;
use Kumwe\App\Identity\Application\Administration\FixedAccessTokenQuotaPolicy;
use Kumwe\App\Identity\Application\Security\PasswordHasher;
use Kumwe\App\Identity\Application\StepUp\StepUpAttemptThrottle;
use Kumwe\App\Identity\Application\StepUp\AdministratorStepUpProvider;
use Kumwe\App\Identity\Application\StepUp\AuthorizationStepUpProofAdapter;
use Kumwe\App\Identity\Application\StepUp\StepUpCredentialStore;
use Kumwe\App\Identity\Application\StepUp\StepUpProofStore;
use Kumwe\App\Identity\Application\StepUp\StepUpProvider;
use Kumwe\App\Identity\Application\StepUp\StepUpRandomSource;
use Kumwe\App\Identity\Application\StepUp\StepUpRecoveryCodeHasher;
use Kumwe\App\Identity\Application\StepUp\StepUpSecretCipher;
use Kumwe\App\Identity\Application\StepUp\TotpAlgorithm;
use Kumwe\App\Identity\Application\StepUp\TotpStepUpProvider;
use Kumwe\App\Identity\Infrastructure\Administration\DoctrineAccessControlRepository;
use Kumwe\App\Identity\Infrastructure\Administration\DoctrineAdministratorIdentityGateway;
use Kumwe\App\Identity\Infrastructure\Administration\DoctrineAdministratorSessionStore;
use Kumwe\App\Identity\Infrastructure\Administration\RedisAuthenticationRateLimiter;
use Kumwe\App\Identity\Infrastructure\Authentication\DoctrineAccessTokenVerifier;
use Kumwe\App\Identity\Infrastructure\Security\NativePasswordHasher;
use Kumwe\App\Identity\Infrastructure\StepUp\AuthenticationRateLimiterStepUpThrottle;
use Kumwe\App\Identity\Infrastructure\StepUp\DoctrineStepUpCredentialStore;
use Kumwe\App\Identity\Infrastructure\StepUp\DoctrineStepUpProofStore;
use Kumwe\App\Identity\Infrastructure\StepUp\NativeStepUpRandomSource;
use Kumwe\App\Identity\Infrastructure\StepUp\Rfc6238Totp;
use Kumwe\App\Identity\Infrastructure\StepUp\SodiumStepUpRecoveryCodeHasher;
use Kumwe\App\Identity\Infrastructure\StepUp\SodiumStepUpSecretCipher;
use Kumwe\App\Infrastructure\Observability\CorrelationContext;
use Kumwe\App\Infrastructure\Observability\LogContextProcessor;
use Kumwe\App\Infrastructure\Observability\LogRedactionProcessor;
use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\MetricRecorder;
use Kumwe\App\Infrastructure\Observability\MetricsAccessPolicy;
use Kumwe\App\Infrastructure\Observability\NullMetricRecorder;
use Kumwe\App\Infrastructure\Observability\ObservabilityContract;
use Kumwe\App\Infrastructure\Observability\PrometheusExposition;
use Kumwe\App\Infrastructure\Observability\RedisMetricRecorder;
use Kumwe\App\Infrastructure\Observability\RuntimeMetricCollector;
use Kumwe\App\Infrastructure\Automation\DoctrineIdempotencyPurger;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\DoctrineIdempotencyLedger;
use Kumwe\App\Infrastructure\Persistence\DoctrineSecretOnceIdempotencyLedger;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionState;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationLock;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationPlan;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationRepository;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\App\Infrastructure\Persistence\Migration\ApplicationAuthorizationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ApplicationAuthorizationMigrationRecovery;
use Kumwe\App\Infrastructure\Persistence\Migration\AuthorizationRecoveryIntegrationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessDefinitionCatalogMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessNumberSequenceMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessRecordHistoryWindowMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessRecordIdempotencyRetentionMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessSecurityPortalMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessTransactionalRuntimeMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ConstraintNameIsolationCompatibilityMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ConstraintNameIsolationPortabilityMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ContentModelIdentifierCollationMigration;
use Kumwe\App\Infrastructure\Persistence\SchemaCollationConvergence;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ContentModelRuntimeMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\DatabaseDrivenPresentationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\DemoProfileProvenanceMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\DocumentContentTypesMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\DynamicSiteContentMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ExtensionContributionCatalogMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\IdempotencyLeaseNullabilityMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\IsolateThemeSurfacesMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\DoctrineMigrationLock;
use Kumwe\App\Infrastructure\Persistence\Migration\DoctrineMigrationRepository;
use Kumwe\App\Infrastructure\Persistence\Migration\DoctrineNonTransactionalMigrationRecovery;
use Kumwe\App\Infrastructure\Persistence\Migration\IndexNameIsolationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\JobRecoveryMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\InstallationGlobalAutomationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\InterfacePresentationPreferenceMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\AuditTamperEvidenceMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\RecordEncryptionKeyRingMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\InterfaceMessageOverrideMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ResourceOwnershipScopeMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\MultilingualContentMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\PeriodPostingLockMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\TranslationGroupSiteOwnershipMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\CredentialLifecycleMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ExtensionSupplyChainMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\MenuPresentationBindingMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\NonTransactionalMigrationRecovery;
use Kumwe\App\Infrastructure\Persistence\Migration\NumberSequenceIdentityMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ResourceOwnershipPortabilityMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\SiteAutomationContextMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioContentProjectionMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioContentAuthoringContextMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioContentAuthoringContextRetentionMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioArtifactRecoveryMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioHostSessionMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioPreviewGrantMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioMediaUploadMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\TokenAndTrustLifecycleMigration;
use Kumwe\App\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\App\Infrastructure\Security\DoctrineHighImpactCredentialGuard;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Infrastructure\Redis\RedisConnectionFactory;
use Kumwe\App\Infrastructure\Redis\RedisRuntime;
use Kumwe\App\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Infrastructure\Mcp\McpMutationGuard;
use Kumwe\App\Infrastructure\Mcp\ReportMcpHandlers;
use Kumwe\App\Infrastructure\Authorization\DoctrineGrantScopeOwnershipReferences;
use Kumwe\App\Infrastructure\Authorization\DoctrineResourceSiteOwnership;
use Kumwe\App\Infrastructure\Authorization\DoctrineResourceSiteOwnershipWriter;
use Kumwe\App\Infrastructure\Authorization\DoctrineSiteGroupRegistry;
use Kumwe\App\Infrastructure\Authorization\DoctrineSiteGroupWriter;
use Kumwe\App\Infrastructure\Presentation\Persistence\DoctrinePresentationAccessGroupRepository;
use Kumwe\App\Infrastructure\Time\SystemClock;
use Kumwe\App\OpenApi\Application\OpenApiContractCache;
use Kumwe\App\OpenApi\Application\OpenApiComponentClaimAdmission;
use Kumwe\App\OpenApi\Application\OpenApiContractCompiler;
use Kumwe\App\OpenApi\Application\OpenApiContractService;
use Kumwe\App\OpenApi\Application\OpenApiExtensionActivationAdmission;
use Kumwe\App\OpenApi\Delivery\Http\OpenApiHandler;
use Kumwe\App\OpenApi\Infrastructure\FilesystemOpenApiContractCache;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Site\Application\PublicPageLocator;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Site\Infrastructure\Persistence\DoctrineSiteSettings;
use Kumwe\App\Site\Infrastructure\Persistence\CachedSiteSettings;
use Kumwe\App\Navigation\Application\NavigationRepository;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Navigation\Application\PublicNavigation;
use Kumwe\App\Navigation\Infrastructure\Persistence\DoctrineNavigationRepository;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Media\Application\MediaStorage;
use Kumwe\App\Media\Infrastructure\FilesystemMediaStorage;
use Kumwe\App\Presentation\Application\ThemeActivationGuard;
use Kumwe\App\Application\Presentation\ThemePackageValidator;
use Kumwe\App\Presentation\Application\ThemeMutationAuthorizer;
use Kumwe\App\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceService;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceFormDecoder;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceQueryDecoder;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferenceManager;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferencePolicy;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferenceRepository;
use Kumwe\App\Presentation\Application\Preference\PresentationPreferenceResolver;
use Kumwe\App\Presentation\Application\Dashboard\DashboardPreferenceFormPresenter;
use Kumwe\App\Application\Presentation\Preference\RegisteredPresentationPreferencePolicy;
use Kumwe\App\Presentation\Infrastructure\DoctrineThemeActivationGuard;
use Kumwe\App\Presentation\Infrastructure\DoctrineAdministratorThemeRecovery;
use Kumwe\App\Presentation\Infrastructure\ConsoleAdministratorThemeRecovery;
use Kumwe\App\Presentation\Application\AdministratorThemeRecovery;
use Kumwe\App\Presentation\Infrastructure\DoctrineThemeMutationAuthorizer;
use Kumwe\App\Presentation\Infrastructure\TwigThemePackageValidator;
use Kumwe\App\Infrastructure\Presentation\Persistence\DoctrinePresentationPreferenceRepository;
use Kumwe\App\Presentation\Asset\ViteAssetManifest;
use Kumwe\App\Presentation\ContentPageRenderService;
use Kumwe\App\Presentation\ContentLayoutCatalog;
use Kumwe\App\Presentation\ContentPresenter;
use Kumwe\App\Presentation\SiteRenderer;
use Kumwe\App\Presentation\RichTextFormatter;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\IsolatedTwigEnvironmentFactory;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\SiteTwigEnvironment;
use Kumwe\App\Portal\Application\DefaultPortalExecutionContextFactory;
use Kumwe\App\Portal\Application\MembershipPortalContextResolver;
use Kumwe\App\Portal\Application\MembershipPortalSessionIdentityLoader;
use Kumwe\App\Portal\Application\PortalAuthenticator;
use Kumwe\App\Portal\Application\PortalContextResolver;
use Kumwe\App\Portal\Application\PortalExecutionContextFactory;
use Kumwe\App\Portal\Application\PortalPrincipalLoader;
use Kumwe\App\Portal\Application\PortalSessionIdentityLoader;
use Kumwe\App\Portal\Application\PortalSessionStore;
use Kumwe\App\Portal\Application\SharedIdentityPortalAuthenticator;
use Kumwe\App\Portal\Http\Handler\PortalHomeHandler;
use Kumwe\App\Portal\Http\Handler\PortalDashboardPreferencesHandler;
use Kumwe\App\Portal\Http\Handler\PortalApprovalHandler;
use Kumwe\App\Portal\Http\Handler\PortalLoginHandler;
use Kumwe\App\Portal\Http\Handler\PortalLogoutHandler;
use Kumwe\App\Portal\Http\Handler\PortalSecurityHandler;
use Kumwe\App\Portal\Http\Middleware\PortalAuthorizationMiddleware;
use Kumwe\App\Portal\Http\Middleware\PortalCsrfMiddleware;
use Kumwe\App\Portal\Http\Middleware\PortalSessionMiddleware;
use Kumwe\App\Portal\Infrastructure\Identity\DoctrinePortalPrincipalLoader;
use Kumwe\App\Portal\Infrastructure\Session\DoctrinePortalSessionStore;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Kumwe\App\Portal\Presentation\Twig\PortalTwigEnvironmentFactory;
use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Localization\Application\CatalogueTranslator;
use Kumwe\App\Localization\Application\LocaleNegotiator;
use Kumwe\App\Localization\Application\MessageCatalogueRepository;
use Kumwe\App\Localization\Application\MessageOverrideRepository;
use Kumwe\App\Localization\Application\MessageOverrideService;
use Kumwe\App\Localization\Application\MessageOverrideStore;
use Kumwe\App\Localization\Application\MessagePatternFormatter;
use Kumwe\App\Localization\Application\MessagePatternValidator;
use Kumwe\App\Localization\Application\SiteDefaultLocale;
use Kumwe\App\Localization\Application\SupportedLocales;
use Kumwe\App\Localization\Application\Translator;
use Kumwe\App\Localization\Http\Middleware\LocaleNegotiationMiddleware;
use Kumwe\App\Localization\Http\Middleware\TranslationScopeMiddleware;
use Kumwe\App\Localization\Infrastructure\ArrayMessageOverrideRepository;
use Kumwe\App\Localization\Infrastructure\CompiledMessageCatalogueRepository;
use Kumwe\App\Localization\Infrastructure\DoctrineMessageOverrideRepository;
use Kumwe\App\Localization\Infrastructure\IntlMessagePatternFormatter;
use Kumwe\App\Localization\Presentation\TranslationTwigExtension;
use Kumwe\App\Workflow\Domain\Workflow;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\Emitter\EmitterStack;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Laminas\Stratigility\MiddlewarePipe;
use Laminas\Stratigility\MiddlewarePipeInterface;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Mezzio\Application;
use Laminas\EventManager\EventManager;
use Laminas\EventManager\EventManagerInterface;
use Mezzio\MiddlewareContainer;
use Mezzio\MiddlewareFactory;
use Mezzio\MiddlewareFactoryInterface;
use Mezzio\Response\ServerRequestErrorResponseGenerator;
use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\ImplicitHeadMiddleware;
use Mezzio\Router\Middleware\ImplicitOptionsMiddleware;
use Mezzio\Router\Middleware\MethodNotAllowedMiddleware;
use Mezzio\Router\Middleware\RouteMiddleware;
use Mezzio\Router\RouteCollector;
use Mezzio\Router\RouteCollectorInterface;
use Mezzio\Router\Route;
use Mezzio\Router\RouterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Redis;

/**
 * Composition root that wires every Kumwe service a process can boot.
 *
 * This is the only place service construction happens. The HTTP front controller, the console entry
 * points and the test kernel all obtain their container from here, so there is exactly one
 * description of how persistence, extensions, delivery and automation fit together and no second
 * service locator to keep in step. The factory takes no constructor arguments and accepts no
 * caller-supplied authority: the kernel proof it mints per build is what binds the authorization
 * gateway and the system principals to services this class composed, and it never leaves the method
 * that created it.
 *
 * @since  2.0.0
 */
final class ContainerFactory
{
    /**
     * Compose the container for a normal boot, with the installed extension runtime loaded.
     *
     * Console services are registered too, so one container serves the front controller, the queue
     * worker and the CLI. Reach for `createRecovery()` instead when the caller must stay isolated
     * from installed extension code.
     *
     * @param   Environment  $environment  Allow-listed process and dotenv variables to configure from.
     *
     * @return  Container  Container with every shared service registered and the extension set active.
     *
     * @throws  \InvalidArgumentException  When a configuration variable is missing or malformed.
     * @throws  \ValueError  When `APP_ENV` names no known runtime or no trusted host is configured.
     * @throws  RuntimeException  When a trusted compiled runtime map is present but fails to load.
     *
     * @since   2.0.0
     */
    public function create(Environment $environment): Container
    {
        return $this->build($environment, true, true);
    }

    /**
     * Builds recovery surfaces without executing any installed extension code.
     *
     * `public/index.php` sends the health probes and the extension trust-key endpoints here, so an
     * operator can observe and re-key an installation whose compiled runtime map is missing,
     * untrusted or unloadable. Core services are wired exactly as in a normal boot; only the
     * extension providers are left unexecuted, which is why this cannot raise a map-loading failure.
     *
     * @param   Environment  $environment  Allow-listed process and dotenv variables to configure from.
     *
     * @return  Container  Container with core services only and an empty active extension set.
     *
     * @throws  \InvalidArgumentException  When a configuration variable is missing or malformed.
     * @throws  \ValueError  When `APP_ENV` names no known runtime or no trusted host is configured.
     *
     * @since   2.0.0
     */
    public function createRecovery(Environment $environment): Container
    {
        return $this->build($environment, true, false);
    }

    /**
     * Wire one container for the requested surface and extension policy.
     *
     * The kernel proof minted here is the object identity that `DenyByDefaultAuthorizationGateway`
     * and `SystemPrincipal` compare against. It is created inside this method, handed only to the
     * registrars that must issue trusted execution contexts, and never shared into the container, so
     * no extension or delivery adapter can obtain it and forge an authorized context.
     *
     * @param   Environment  $environment  Allow-listed process and dotenv variables to configure from.
     * @param   bool         $console      Whether to register the console commands, job handlers and worker.
     * @param   bool         $loadRuntime  Whether installed extension providers may execute during the build.
     *
     * @return  Container  Container with every registrar applied, ready to resolve services.
     *
     * @throws  \InvalidArgumentException  When a configuration variable is missing or malformed.
     * @throws  \ValueError  When `APP_ENV` names no known runtime or no trusted host is configured.
     * @throws  RuntimeException  When a trusted compiled runtime map is present but fails to load.
     *
     * @since   2.0.0
     */
    private function build(Environment $environment, bool $console, bool $loadRuntime): Container
    {
        // The proof never crosses the production composition boundary. In-process PHP
        // extensions execute with the same process authority as core and are trusted code;
        // integrations that need isolation must use an out-of-process delivery adapter.
        $kernelProof = new \stdClass();
        $configuration = (new ConfigurationFactory())->create($environment);
        $container = new Container();
        $root = dirname(__DIR__, 2);

        $container->share(Container::class, $container, true);
        $container->alias(ContainerInterface::class, Container::class);
        $container->share(ApplicationConfiguration::class, $configuration, true);
        (new NativeComputationFactory())->register($container, $environment);
        $container->share(ClockInterface::class, new SystemClock(), true);
        $container->share(AutomationJobFormRegistry::class, static fn (
            Container $container,
        ): AutomationJobFormRegistry => AutomationJobFormRegistry::core(
            self::service($container, Translator::class),
        ), true);
        $container->share(JitterSource::class, new CryptographicJitterSource(), true);
        $container->share(RetryPolicy::class, static fn (Container $container): RetryPolicy => new RetryPolicy(
            self::service($container, ClockInterface::class),
            self::service($container, JitterSource::class),
        ), true);
        $container->share(EventManager::class, new EventManager(), true);
        $container->alias(EventManagerInterface::class, EventManager::class);

        $this->registerObservability($container, $configuration, $root, $console);
        $this->registerLogging($container, $configuration);
        $this->registerPersistence($container, $configuration, $root, $kernelProof, $loadRuntime);
        $this->registerLocalization($container, $configuration, $root);
        $this->registerExtensions($container, $configuration, $root, $kernelProof, $loadRuntime);
        $routeCacheFile = self::routeCacheFile(
            $root,
            $configuration->release,
            $loadRuntime,
            self::service($container, RuntimeMaterializationState::class),
        );
        $container->share('config', [
            'debug' => $configuration->debug,
            'router' => [
                'detect_duplicates' => true,
                'fastroute' => [
                    'cache_enabled' => $configuration->isProduction(),
                    'cache_file' => $routeCacheFile,
                ],
            ],
        ], true);
        $this->registerBusinessSurfaces($container, $root, $kernelProof);
        $this->registerMcp($container, $root);
        $this->registerHttp($container, $configuration, $root, $routeCacheFile, $kernelProof, $loadRuntime);
        if ($console) {
            $this->registerConsole($container, $kernelProof);
        }

        return $container;
    }

    /**
     * Name a route cache for the exact immutable graph this kernel registers.
     *
     * Recovery deliberately omits portal and extension routes, so it must never share a dispatcher
     * with the full kernel. The full cache also follows the release and trusted runtime publication:
     * the runtime watcher reloads PHP-FPM without clearing its cache volume after activation, and a
     * fixed filename would otherwise keep routes from the superseded generation. Hashing the identity
     * keeps deployment-provided release values out of the filesystem path. New prefixes also retire
     * the legacy shared `routes.php` cache on upgrade.
     *
     * @param   string                       $root             Absolute repository root.
     * @param   string                       $release          Immutable application release identifier.
     * @param   bool                         $loadRuntime      Whether the full extension runtime is loaded.
     * @param   RuntimeMaterializationState  $materialization  Exact local runtime publication inspected at boot.
     *
     * @return  string  Absolute cache file unique to the kernel's route graph.
     *
     * @since   2.0.0
     */
    private static function routeCacheFile(
        string $root,
        string $release,
        bool $loadRuntime,
        RuntimeMaterializationState $materialization,
    ): string {
        $surface = $loadRuntime ? 'runtime' : 'recovery';
        $identity = [$surface, $release];
        if ($loadRuntime) {
            $identity[] = $materialization->trusted ? 'trusted' : 'untrusted';
            $identity[] = (string) $materialization->generation;
            $identity[] = $materialization->publicationChecksum;
        }

        return sprintf(
            '%s/storage/cache/routes-%s-%s.php',
            $root,
            $surface,
            hash('sha256', implode("\0", $identity)),
        );
    }

    /**
     * Register the declared observability contract and everything composed from it.
     *
     * `config/observability.php` is loaded exactly here and nowhere else, which is what makes it the
     * single source of truth rather than a second one: the logger's format, level and redaction, the
     * metric catalogue's forbidden labels, and the exposition endpoint's exposure all read the same
     * instance. A declaration the runtime cannot honour raises during composition, so the failure is a
     * boot error an operator sees rather than a silent divergence they do not.
     *
     * @param   Container                 $container      Container being composed.
     * @param   ApplicationConfiguration  $configuration  Boot configuration for release and metric exposure.
     * @param   string                    $root           Absolute repository root the contract is loaded from.
     * @param   bool                      $console        Whether this process is a console rather than a request.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the declared contract is missing or malformed.
     *
     * @since   2.0.0
     */
    private function registerObservability(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
        bool $console,
    ): void {
        $contract = ObservabilityContract::load($root);
        $surface = $console ? 'console' : 'http';
        $correlation = new CorrelationContext();
        $container->share(ObservabilityContract::class, $contract, true);
        $container->share(CorrelationContext::class, $correlation, true);
        // Both processors are invokable, which the container would otherwise read as a factory.
        $redaction = new LogRedactionProcessor($contract);
        $stamp = new LogContextProcessor($contract, $correlation, $configuration->release, $surface);
        $container->share(LogRedactionProcessor::class, static fn (): LogRedactionProcessor => $redaction, true);
        $container->share(LogContextProcessor::class, static fn (): LogContextProcessor => $stamp, true);
        $catalog = MetricCatalog::create($contract, $configuration->release, $surface);
        $container->share(MetricCatalog::class, $catalog, true);
        $container->share(PrometheusExposition::class, new PrometheusExposition(), true);
        $container->share(MetricsAccessPolicy::class, new MetricsAccessPolicy(
            $configuration->metricsEnabled ?? $contract->metricsEnabled,
            $contract->metricsPublic,
            $configuration->metricsToken,
        ), true);
        $enabled = $configuration->metricsEnabled ?? $contract->metricsEnabled;
        $container->share(MetricRecorder::class, static fn (Container $container): MetricRecorder =>
            $enabled
                ? new RedisMetricRecorder(self::service($container, RedisRuntime::class), $catalog)
                : new NullMetricRecorder(), true);
        $container->share(RuntimeMetricCollector::class, static fn (
            Container $container,
        ): RuntimeMetricCollector => new RuntimeMetricCollector(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
            new LocalRuntimeReadinessProbe(self::service($container, ExtensionRuntimeMapCompiler::class)),
            $configuration->release,
            $surface,
        ), true);
    }

    /**
     * Register the Monolog logger and its PSR-3 alias, wired to the declared logging contract.
     *
     * Records go to the destination the contract names — `php://stderr`, so the container runtime owns
     * log shipping rather than the application — formatted as the JSON the contract declares, stamped
     * with the required context, and passed through the redaction the contract lists. The emission
     * level is the contract's `default_level`, raised to debug by a debug deployment and overridden
     * outright by `KUMWE_LOG_LEVEL`, which is what lets an operator quieten or open up the log stream
     * without also widening the response detail the debug flag controls.
     *
     * Processors run context-first and redaction-last, so nothing a processor adds can bypass the
     * redaction list. Stack traces are switched off in the formatter as well as stripped by the
     * processor, because a trace holds frame arguments and frame arguments hold secrets.
     *
     * @param   Container                 $container      Container being composed.
     * @param   ApplicationConfiguration  $configuration  Boot configuration for debug, release and log level.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerLogging(Container $container, ApplicationConfiguration $configuration): void
    {
        $container->share(Logger::class, static function (Container $container) use ($configuration): Logger {
            $contract = self::service($container, ObservabilityContract::class);
            $handler = new StreamHandler(
                $contract->logDestination,
                self::logLevel($configuration->logLevel ?? (
                    $configuration->debug ? 'debug' : $contract->logDefaultLevel
                )),
            );
            $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true, true, false));
            $logger = new Logger('kumwe');
            $logger->pushHandler($handler);
            $logger->pushProcessor(self::service($container, LogRedactionProcessor::class));
            $logger->pushProcessor(self::service($container, LogContextProcessor::class));

            return $logger;
        }, true);
        $container->alias(LoggerInterface::class, Logger::class);
    }

    /**
     * Resolve a contract level name onto the Monolog level it selects.
     *
     * The mapping is explicit rather than delegated to `Level::fromName()` so the contract's
     * lower-case vocabulary is the only spelling this kernel accepts, and so an unknown name is a
     * refusal at composition rather than a level quietly resolving to something else.
     *
     * @param   string  $name  Level name from the contract or the `KUMWE_LOG_LEVEL` override.
     *
     * @return  Level  The selected Monolog level.
     *
     * @throws  \InvalidArgumentException  When the name is not one the contract declares.
     *
     * @since   2.0.0
     */
    private static function logLevel(string $name): Level
    {
        return match ($name) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => throw new \InvalidArgumentException('The configured log level is not a known level.'),
        };
    }

    /**
     * Earlier checksums this build still accepts for migrations it ships, by migration ID.
     *
     * The migration plan accepts them for a migration a previous build already applied, and the
     * non-transactional recovery accepts them for an attempt a previous build journaled and left
     * unfinished, so both read the one list.
     *
     * @return  array<string, list<string>>  Accepted historical checksums by migration ID.
     *
     * @since   2.0.0
     */
    private static function acceptedHistoricalChecksums(): array
    {
        return [
            // Previously distributed builds used a DBAL-equivalent static-analysis rewrite, then
            // included a later ownership backfill here. The immutable source is restored;
            // AuthorizationRecoveryIntegrationMigration owns and verifies the idempotent postcondition.
            JobRecoveryMigration::ID => [
                '5e55e74ae3027ecc5d4843e045cf19a3e07d0b7be1f2ce556807bb67eda61947',
                '4d7fc30104c21bda0c00947fb82bce1333daa0d542e7292ee4e96bbda1c83b5d',
            ],
            // Existing databases keep the checksum of the immutable published rename. Fresh
            // databases run the corrected compatibility implementation in that same plan slot.
            ConstraintNameIsolationCompatibilityMigration::ID => [
                ConstraintNameIsolationCompatibilityMigration::PUBLISHED_CHECKSUM,
            ],
            // KUMWE-MIG-2026-004 moved the site and execution-context values to kumwe/access-context.
            // The nine migrations that name them changed only their imports; their statements are
            // unchanged, so databases migrated before the move keep the checksums recorded then.
            ApplicationAuthorizationMigration::ID => [
                '484705ff88bf14bc4f92a63cff2fcb613a739aa147a0e76c152e4f564f129bf0',
            ],
            ContentModelRuntimeMigration::ID => [
                '5b32b7007ee4819ad67fef186cee61c4f76952dd462cb99b3dca891d9549b79d',
            ],
            DynamicSiteContentMigration::ID => [
                'e42d07ec8c59e29293e0aac77f2acdf3c35bc8c57314af4375d2d7585e259f05',
            ],
            BusinessSecurityPortalMigration::ID => [
                'adac395af8bed6dde8b179895e7b59e46eb220a736c902014f7ea1db85d754c9',
            ],
            DocumentContentTypesMigration::ID => [
                '939135ac28684c06cf09d41564524e56f060e9ec8448c05f4aab2e5de8821eff',
            ],
            ResourceOwnershipScopeMigration::ID => [
                '71ee7868025464ddf3465f00f9b2e7825ea9c450307d547bee58a48071386e86',
            ],
            InterfaceMessageOverrideMigration::ID => [
                '069b5375c77fb60dccf65d57152dc8a8f9da57a3355dd410c8621e96d9d1bec6',
            ],
            PeriodPostingLockMigration::ID => [
                'e887d43fd7c155f2f633bab2220d699a8007dd2edd930d617083fc969b6364a0',
            ],
            StudioHostSessionMigration::ID => [
                '9579330402183aedb650109583b9c10531fa84ba5172e0a377319d9cf4c61eda',
            ],
        ];
    }

    /**
     * Register the storage, authorization and domain-service half of the graph.
     *
     * Every entry is a lazy shared factory, so composing a container opens no database or Redis
     * connection: the first `get()` for a service does. The kernel proof reaches the authorization
     * gateway, the access-token verifier and the identity, session and scheduling stores from here,
     * which is what makes the execution contexts they issue trustworthy.
     *
     * @param   Container                 $container      Container being composed.
     * @param   ApplicationConfiguration  $configuration  Boot configuration for credentials, limits and secrets.
     * @param   string                    $root           Absolute path of the repository root.
     * @param   \stdClass                 $kernelProof    Composition-root capability the gateway is bound to.
     * @param   bool                      $portalEnabled  Whether to register ordinary-user portal services.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerPersistence(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
        object $kernelProof,
        bool $portalEnabled,
    ): void {
        $provenance = $kernelProof;
        $databaseConfiguration = $configuration->database;
        $container->share(Connection::class, static fn (): Connection =>
            (new DoctrineConnectionFactory($databaseConfiguration))->create(), true);
        $container->share(TableNames::class, static fn (Container $container): TableNames => new TableNames(
            self::service($container, Connection::class),
            $databaseConfiguration->tablePrefix,
        ), true);
        $container->share(TransactionManager::class, static fn (Container $container): TransactionManager =>
            new DoctrineTransactionManager(self::service($container, Connection::class)), true);
        $container->share(TransactionState::class, static fn (Container $container): TransactionState =>
            new DoctrineTransactionState(self::service($container, Connection::class)), true);
        $redisConfiguration = $configuration->redis;
        $container->share(Redis::class, static fn (): Redis =>
            (new RedisConnectionFactory($redisConfiguration))->create(), true);
        $container->share(RedisRuntime::class, static fn (Container $container): RedisRuntime =>
            new RedisRuntime(self::service($container, Redis::class)), true);
        $container->share(AuthenticationRateLimiter::class, static fn (
            Container $container,
        ): AuthenticationRateLimiter => new RedisAuthenticationRateLimiter(
            self::service($container, RedisRuntime::class),
        ), true);
        $container->share(PasswordHasher::class, new NativePasswordHasher(), true);
        $container->share(HighImpactCredentialGuard::class, static fn (
            Container $container,
        ): HighImpactCredentialGuard => new DoctrineHighImpactCredentialGuard(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, PasswordHasher::class),
            self::service($container, AuthenticationRateLimiter::class),
        ), true);
        $container->share(AccessTokenQuotaPolicy::class, new FixedAccessTokenQuotaPolicy(), true);
        $container->share(Workflow::class, new Workflow(), true);
        $authorizationPolicies = new AuthorizationPolicyRegistry();
        $container->share(AuthorizationPolicyRegistry::class, $authorizationPolicies, true);
        $container->share(AuthorizationGateway::class, static fn (Container $container): AuthorizationGateway =>
            new DenyByDefaultAuthorizationGateway(
                $provenance,
                self::service($container, AuthorizationPolicyRegistry::class),
                self::service($container, MembershipContextValidator::class),
                self::service($container, ResourceSiteOwnership::class),
                new StructuredLogAuthorizationDecisionRecorder(self::service($container, LoggerInterface::class)),
            ), true);
        $container->share(ResourceSiteOwnership::class, static fn (Container $container): ResourceSiteOwnership =>
            new DoctrineResourceSiteOwnership(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, SiteGroupRegistry::class),
            ), true);
        // Business-group ownership scope: declared groups, the frozen per-category scope table, the
        // guarded scope-change operations and the consolidated read capability.
        $container->share(DoctrineSiteGroupRegistry::class, static fn (
            Container $container,
        ): DoctrineSiteGroupRegistry => new DoctrineSiteGroupRegistry(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->alias(SiteGroupRegistry::class, DoctrineSiteGroupRegistry::class);
        $container->share(SiteGroupWriter::class, static fn (Container $container): SiteGroupWriter =>
            new DoctrineSiteGroupWriter(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, DoctrineSiteGroupRegistry::class),
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(ResourceOwnershipScopePolicy::class, new ResourceOwnershipScopePolicy(), true);
        $container->share(ResourceOwnershipReferences::class, static fn (
            Container $container,
        ): ResourceOwnershipReferences => new CompositeResourceOwnershipReferences([
            new DoctrineGrantScopeOwnershipReferences(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ),
        ]), true);
        $container->share(SiteGroupAdministration::class, static fn (
            Container $container,
        ): SiteGroupAdministration => new SiteGroupAdministration(
            self::service($container, AuthorizationGateway::class),
            self::service($container, SiteGroupRegistry::class),
            self::service($container, SiteGroupWriter::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(ResourceOwnershipScopeService::class, static fn (
            Container $container,
        ): ResourceOwnershipScopeService => new ResourceOwnershipScopeService(
            self::service($container, AuthorizationGateway::class),
            self::service($container, ResourceSiteOwnership::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            self::service($container, ResourceOwnershipScopePolicy::class),
            self::service($container, ResourceOwnershipReferences::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(ConsolidatedGroupReportScope::class, static fn (
            Container $container,
        ): ConsolidatedGroupReportScope => new ConsolidatedGroupReportScope(
            self::service($container, AuthorizationGateway::class),
            self::service($container, SiteGroupRegistry::class),
        ), true);
        $container->share(ResourceSiteOwnershipWriter::class, static fn (
            Container $container,
        ): ResourceSiteOwnershipWriter => new DoctrineResourceSiteOwnershipWriter(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(MembershipDirectory::class, static fn (Container $container): MembershipDirectory =>
            new DoctrineMembershipDirectory(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->alias(MembershipContextValidator::class, MembershipDirectory::class);
        $container->share(AuthorizationStepUpProofAdapter::class, new AuthorizationStepUpProofAdapter(), true);
        $container->share(StepUpSecretCipher::class, new SodiumStepUpSecretCipher(
            hash_hkdf('sha256', $configuration->secret, 32, 'kumwe-step-up-encryption-v1'),
        ), true);
        $container->share(StepUpRecoveryCodeHasher::class, new SodiumStepUpRecoveryCodeHasher(
            hash_hkdf('sha256', $configuration->secret, 32, 'kumwe-step-up-recovery-v1'),
        ), true);
        $container->share(StepUpRandomSource::class, new NativeStepUpRandomSource(), true);
        $container->share(TotpAlgorithm::class, new Rfc6238Totp(), true);
        $container->share(StepUpAttemptThrottle::class, static fn (
            Container $container,
        ): StepUpAttemptThrottle => new AuthenticationRateLimiterStepUpThrottle(
            self::service($container, AuthenticationRateLimiter::class),
            hash_hkdf('sha256', $configuration->secret, 32, 'kumwe-step-up-throttle-v1'),
        ), true);
        $container->share(StepUpCredentialStore::class, static fn (
            Container $container,
        ): StepUpCredentialStore => new DoctrineStepUpCredentialStore(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(StepUpProofStore::class, static fn (
            Container $container,
        ): StepUpProofStore => new DoctrineStepUpProofStore(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(PortalPrincipalLoader::class, static fn (
            Container $container,
        ): PortalPrincipalLoader => new DoctrinePortalPrincipalLoader(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            $provenance,
        ), true);
        if ($portalEnabled) {
            $container->share(PortalAuthenticator::class, static fn (
                Container $container,
            ): PortalAuthenticator => new SharedIdentityPortalAuthenticator(
                self::service($container, AdministratorIdentityGateway::class),
                self::service($container, PortalPrincipalLoader::class),
            ), true);
            $container->share(PortalContextResolver::class, static fn (
                Container $container,
            ): PortalContextResolver => new MembershipPortalContextResolver(
                self::service($container, MembershipDirectory::class),
                SiteContext::fromString($configuration->publicSite),
            ), true);
            $container->share(PortalSessionIdentityLoader::class, static fn (
                Container $container,
            ): PortalSessionIdentityLoader => new MembershipPortalSessionIdentityLoader(
                self::service($container, PortalPrincipalLoader::class),
                self::service($container, MembershipDirectory::class),
            ), true);
            $container->share(DoctrinePortalSessionStore::class, static fn (
                Container $container,
            ): DoctrinePortalSessionStore => new DoctrinePortalSessionStore(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, PortalSessionIdentityLoader::class),
                self::service($container, ClockInterface::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, TransactionManager::class),
                hash_hkdf('sha256', $configuration->secret, 32, 'kumwe-portal-session-binding-v1'),
                new \DateInterval('PT' . $configuration->administratorSessionSeconds . 'S'),
            ), true);
            $container->alias(PortalSessionStore::class, DoctrinePortalSessionStore::class);
            $container->share(PortalExecutionContextFactory::class, new DefaultPortalExecutionContextFactory(), true);
            $container->share(StepUpProvider::class, static fn (Container $container): StepUpProvider =>
                new TotpStepUpProvider(
                    self::service($container, StepUpCredentialStore::class),
                    self::service($container, StepUpSecretCipher::class),
                    self::service($container, StepUpRecoveryCodeHasher::class),
                    self::service($container, StepUpRandomSource::class),
                    self::service($container, TotpAlgorithm::class),
                    self::service($container, StepUpAttemptThrottle::class),
                    self::service($container, DoctrinePortalSessionStore::class),
                    self::service($container, StepUpProofStore::class),
                    self::service($container, TransactionManager::class),
                    self::service($container, AuditRecorder::class),
                    self::service($container, ClockInterface::class),
                ), true);
        }
        $container->share(ApprovalRepository::class, static fn (Container $container): ApprovalRepository =>
            new DoctrineApprovalRepository(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(ApprovalQueryRepository::class, static fn (
            Container $container,
        ): ApprovalQueryRepository => new DoctrineApprovalQueryRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(ApprovalQueryService::class, static fn (
            Container $container,
        ): ApprovalQueryService => new ApprovalQueryService(
            self::service($container, ApprovalQueryRepository::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, MembershipDirectory::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(StepUpProofConsumer::class, static fn (
            Container $container,
        ): StepUpProofConsumer => new DoctrineStepUpProofConsumer(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(BusinessSecurityAdministrationRepository::class, static fn (
            Container $container,
        ): BusinessSecurityAdministrationRepository => new DoctrineBusinessSecurityAdministrationRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
        ), true);
        $container->share(BusinessSecurityAdministrationService::class, static fn (
            Container $container,
        ): BusinessSecurityAdministrationService => new BusinessSecurityAdministrationService(
            self::service($container, BusinessSecurityAdministrationRepository::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuthorizationPolicyRegistry::class),
            self::service($container, MembershipDirectory::class),
            self::service($container, StepUpProofConsumer::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(ApprovalService::class, static fn (Container $container): ApprovalService =>
            new ApprovalService(
                self::service($container, ApprovalRepository::class),
                self::service($container, StepUpProofConsumer::class),
                self::service($container, MembershipDirectory::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(MigrationRepository::class, static fn (Container $container): MigrationRepository =>
            new DoctrineMigrationRepository(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(
            ApplicationAuthorizationMigrationRecovery::class,
            static fn (Container $container): ApplicationAuthorizationMigrationRecovery =>
                new ApplicationAuthorizationMigrationRecovery(
                    self::service($container, Connection::class),
                    self::service($container, TableNames::class),
                ),
            true,
        );
        $container->share(
            NonTransactionalMigrationRecovery::class,
            static fn (Container $container): NonTransactionalMigrationRecovery =>
                new DoctrineNonTransactionalMigrationRecovery(
                    self::service($container, Connection::class),
                    self::service($container, TableNames::class),
                    self::service($container, ApplicationAuthorizationMigrationRecovery::class),
                    self::acceptedHistoricalChecksums(),
                ),
            true,
        );
        $container->share(MigrationLock::class, static fn (Container $container): MigrationLock =>
            new DoctrineMigrationLock(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(ExpiredMigrationLockRecovery::class, static function (
            Container $container,
        ): ExpiredMigrationLockRecovery {
            $lock = self::service($container, MigrationLock::class);
            if (!$lock instanceof ExpiredMigrationLockRecovery) {
                throw new \RuntimeException('The migration lock has no expired-owner recovery implementation.');
            }

            return $lock;
        }, true);
        $container->share(MigrationLockRecoveryService::class, static fn (
            Container $container,
        ): MigrationLockRecoveryService => new MigrationLockRecoveryService(
            self::service($container, ExpiredMigrationLockRecovery::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(AccessTokenVerifier::class, static fn (Container $container): AccessTokenVerifier =>
            new DoctrineAccessTokenVerifier(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, ClockInterface::class),
                $provenance,
            ), true);
        $container->share(TrustStoreRepository::class, static fn (Container $container): TrustStoreRepository =>
            new DoctrineTrustStoreRepository(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(
            PublicKeyPackageSignatureVerifier::class,
            new SodiumPublicKeyPackageSignatureVerifier(),
            true,
        );
        $container->share(AdministratorIdentityGateway::class, static fn (
            Container $container,
        ): AdministratorIdentityGateway => new DoctrineAdministratorIdentityGateway(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, PasswordHasher::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            self::service($container, AuthenticationRateLimiter::class),
            self::service($container, AuditRecorder::class),
            self::service($container, AccessTokenQuotaPolicy::class),
            $configuration->secret,
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuthorizationPolicyRegistry::class),
            self::service($container, TokenDelegationPreauthorizer::class),
            self::service($container, TokenRotationPreauthorizer::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            $provenance,
        ), true);
        $container->share(DoctrineAdministratorSessionStore::class, static fn (
            Container $container,
        ): DoctrineAdministratorSessionStore => new DoctrineAdministratorSessionStore(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
            $configuration->secret,
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            $provenance,
            $configuration->administratorSessionSeconds,
            self::service($container, MembershipDirectory::class),
        ), true);
        $container->alias(AdministratorSessionStore::class, DoctrineAdministratorSessionStore::class);
        $container->share(AdministratorStepUpProvider::class, static fn (
            Container $container,
        ): AdministratorStepUpProvider => new TotpStepUpProvider(
            self::service($container, StepUpCredentialStore::class),
            self::service($container, StepUpSecretCipher::class),
            self::service($container, StepUpRecoveryCodeHasher::class),
            self::service($container, StepUpRandomSource::class),
            self::service($container, TotpAlgorithm::class),
            self::service($container, StepUpAttemptThrottle::class),
            self::service($container, DoctrineAdministratorSessionStore::class),
            self::service($container, StepUpProofStore::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(AuditRecorder::class, static fn (Container $container): AuditRecorder =>
            new DoctrineAuditRecorder(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(AuditArchiveStorage::class, static fn (): AuditArchiveStorage =>
            new FilesystemAuditArchiveStorage($root . '/storage/private/audit-archives'), true);
        $container->share(AuditAnchorWriter::class, static fn (Container $container): AuditAnchorWriter =>
            new DoctrineAuditAnchorWriter(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
                self::service($container, AuthorizationGateway::class),
            ), true);
        $container->share(AuditTrailVerifier::class, static fn (Container $container): AuditTrailVerifier =>
            new DoctrineAuditTrailVerifier(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, AuthorizationGateway::class),
            ), true);
        $container->share(AuditTrailExporter::class, static fn (Container $container): AuditTrailExporter =>
            new DoctrineAuditTrailExporter(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuditArchiveStorage::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
                self::service($container, AuthorizationGateway::class),
            ), true);
        $container->share(AuditRetentionService::class, static fn (Container $container): AuditRetentionService =>
            new DoctrineAuditRetentionService(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuditTrailExporter::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
                self::service($container, AuthorizationGateway::class),
            ), true);
        $container->share(ContentRepository::class, static fn (Container $container): ContentRepository =>
            new DoctrineContentRepository(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(TranslationGroupRepository::class, static fn (
            Container $container,
        ): TranslationGroupRepository => new DoctrineTranslationGroupRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(BusinessDefinitionRepository::class, static fn (
            Container $container,
        ): BusinessDefinitionRepository => new DoctrineBusinessDefinitionRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(BusinessSchemaPlanRepository::class, static fn (
            Container $container,
        ): BusinessSchemaPlanRepository => new DoctrineBusinessSchemaPlanRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(BusinessSchemaInstallationRepository::class, static fn (
            Container $container,
        ): BusinessSchemaInstallationRepository => new DoctrineBusinessSchemaInstallationRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(BusinessSchemaRecoveryEvidenceRepository::class, static fn (
            Container $container,
        ): BusinessSchemaRecoveryEvidenceRepository => new DoctrineBusinessSchemaRecoveryEvidenceRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(PhysicalSchemaGateway::class, static fn (
            Container $container,
        ): PhysicalSchemaGateway => new DoctrinePhysicalSchemaGateway(
            self::service($container, Connection::class),
        ), true);
        $container->share(BusinessSchemaExecutionLock::class, static fn (
            Container $container,
        ): BusinessSchemaExecutionLock => new DoctrineBusinessSchemaExecutionLock(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(BusinessSchemaExecutionStateGuard::class, static fn (
            Container $container,
        ): BusinessSchemaExecutionStateGuard => new DoctrineBusinessSchemaExecutionStateGuard(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(BusinessSchemaEnvironment::class, new ConfiguredBusinessSchemaEnvironment(
            $databaseConfiguration->driver,
            $databaseConfiguration->serverVersion,
            $configuration->release,
        ), true);
        $container->share(
            BusinessDefinitionCompatibilityAnalyzer::class,
            new BusinessDefinitionCompatibilityAnalyzer(),
            true,
        );
        $container->share(ContentModelRepository::class, static fn (Container $container): ContentModelRepository =>
            new DoctrineContentModelRepository(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(JsonSchemaValidator::class, new JsonSchemaValidator(), true);
        $container->share(SchemaCompatibilityChecker::class, new SchemaCompatibilityChecker(), true);
        $container->share(ContentModelService::class, static fn (Container $container): ContentModelService =>
            new ContentModelService(
                self::service($container, ContentModelRepository::class),
                self::service($container, JsonSchemaValidator::class),
                self::service($container, SchemaCompatibilityChecker::class),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, AuditRecorder::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(ContentService::class, static fn (Container $container): ContentService =>
            new ContentService(
                self::service($container, ContentRepository::class),
                self::service($container, AuditRecorder::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                self::service($container, Workflow::class),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, ContentModelRepository::class),
                self::service($container, JsonSchemaValidator::class),
                self::service($container, TranslationGroupRepository::class),
                self::service($container, ExtensionContributionRegistrySet::class)->contentTranslationGroups(),
            ), true);
        $container->share(
            ContentProjectionBindingRepository::class,
            static fn (Container $container): ContentProjectionBindingRepository =>
                new DoctrineContentProjectionBindingRepository(
                    self::service($container, Connection::class),
                    self::service($container, TableNames::class),
                ),
            true,
        );
        $container->alias(ContentBlueprintBindingStore::class, ContentProjectionBindingRepository::class);
        $container->share(
            StudioContentFieldDisclosure::class,
            new RecordAuthorizedStudioContentFieldDisclosure(),
            true,
        );
        $container->share(
            StudioDocumentSchemaRegistry::class,
            static fn (): StudioDocumentSchemaRegistry => StudioDocumentSchemaRegistry::fromVendoredCorpus(),
            true,
        );
        $container->share(ContentStudioProjector::class, static fn (
            Container $container,
        ): ContentStudioProjector => new ContentStudioProjector(
            self::service($container, StudioDocumentSchemaRegistry::class),
            self::service($container, StudioContentFieldDisclosure::class),
            self::service($container, JsonSchemaValidator::class),
        ), true);
        $container->share(StudioContentProjectionService::class, static fn (
            Container $container,
        ): StudioContentProjectionService => new StudioContentProjectionService(
            self::service($container, ContentModelService::class),
            self::service($container, ContentService::class),
            self::service($container, ContentProjectionBindingRepository::class),
            self::service($container, ContentStudioProjector::class),
        ), true);
        $container->share(StudioContentCompositionService::class, static fn (
            Container $container,
        ): StudioContentCompositionService => new StudioContentCompositionService(
            self::service($container, StudioContentProjectionService::class),
            self::service($container, ContentProjectionBindingRepository::class),
            self::service($container, ContentBlueprintBindingStore::class),
            self::service($container, StudioArtifactAdmission::class),
            self::service($container, StudioArtifactRepository::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, StudioCompositionContributionCatalog::class),
            self::service($container, StudioPublishedTheme::class),
        ), true);
        $container->share(StudioPublishedTheme::class, static fn (
            Container $container,
        ): StudioPublishedTheme => new StudioPublishedTheme(
            self::service($container, SiteSettings::class),
            self::service($container, ActiveExtensionSet::class),
            self::service($container, StudioBuiltInThemeRelease::class),
        ), true);
        $container->share(StudioBuiltInThemeRelease::class, static fn (): StudioBuiltInThemeRelease =>
            StudioBuiltInThemeRelease::fromDeployment(
                $root,
                $configuration->release,
                new RuntimeArtifactDigester(),
            ), true);
        $container->share(StudioCompositionContributionCatalog::class, static fn (
            Container $container,
        ): StudioCompositionContributionCatalog => new StudioCompositionContributionCatalog(
            self::service($container, ExtensionContributionRegistrySet::class),
            self::service($container, StudioBlockRendererRuntime::class),
        ), true);
        $container->share(StudioReleaseRecord::class, static function () use ($root): StudioReleaseRecord {
            $record = file_get_contents($root . '/resources/studio-contract/studio-release.json');
            if (!is_string($record)) {
                throw new RuntimeException('The canonical Studio release record is unavailable.');
            }

            return StudioReleaseRecord::fromJson($record);
        }, true);
        $container->share(ContentStudioAuthoringTargetResolver::class, static fn (
            Container $container,
        ): ContentStudioAuthoringTargetResolver => new ContentStudioAuthoringTargetResolver(
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(
            ContentStudioAuthoringContextRepository::class,
            static fn (Container $container): ContentStudioAuthoringContextRepository =>
                new DoctrineContentStudioAuthoringContextRepository(
                    self::service($container, Connection::class),
                    self::service($container, TableNames::class),
                ),
            true,
        );
        $container->share(
            StudioBrowserAssetLocator::class,
            static fn (): StudioBrowserAssetLocator =>
                StudioBrowserAssetLocator::npmPackages($configuration->studioBrowserBaseUrl),
            true,
        );
        $container->share(StudioCoreCatalog::class, static fn (Container $container): StudioCoreCatalog =>
            StudioCoreCatalog::fromFile(
                $root . '/resources/studio-contract/core-catalog.json',
                self::service($container, StudioReleaseRecord::class)->release,
            ), true);
        $container->share(StudioDeploymentEmitter::class, static fn (
            Container $container,
        ): StudioDeploymentEmitter => new StudioDeploymentEmitter(
            self::service($container, StudioDocumentSchemaRegistry::class),
            StudioContractResources::releaseRecord(),
        ), true);
        $container->share(
            StudioContextualAuthoringAvailability::class,
            static fn (Container $container): StudioContextualAuthoringAvailability =>
                new PinnedStudioContextualAuthoringAvailability(
                    $root,
                    self::studioContextualAuthoringQualification(),
                    self::service($container, StudioBrowserAssetLocator::class),
                ),
            true,
        );
        $container->share(ContentStudioAuthoringCatalog::class, static fn (
            Container $container,
        ): ContentStudioAuthoringCatalog => new ContentStudioAuthoringCatalog(
            self::service($container, StudioCompositionContributionCatalog::class),
            self::service($container, StudioCoreCatalog::class),
        ), true);
        $container->share(
            StudioContextualAuthoringConfigurationProvider::class,
            static fn (Container $container): StudioContextualAuthoringConfigurationProvider =>
                new HostedContentStudioAuthoringConfigurationProvider(
                    self::service($container, ContentStudioAuthoringContextAuthority::class),
                    self::service($container, StudioHostSessionAuthority::class),
                    self::service($container, ContentStudioAuthoringCatalog::class),
                    self::service($container, StudioPublishedTheme::class),
                    self::service($container, ActiveLocale::class),
                    self::service($container, SiteSettings::class),
                    self::service($container, StudioDeploymentEmitter::class),
                    self::service($container, StudioBrowserAssetLocator::class),
                    self::service($container, LoggerInterface::class),
                ),
            true,
        );
        $container->share(ContentStudioAuthoringService::class, static fn (
            Container $container,
        ): ContentStudioAuthoringService => new ContentStudioAuthoringService(
            self::service($container, ContentStudioAuthoringContextAuthority::class),
            self::service($container, ContentService::class),
            self::service($container, ContentModelService::class),
            self::service($container, ContentStudioProjector::class),
            self::service($container, StudioContentCompositionService::class),
            self::service($container, ContentProjectionBindingRepository::class),
            self::service($container, StudioPublishedTheme::class),
            self::service($container, StudioDocumentSchemaRegistry::class),
            self::service($container, ContentStudioAuthoringCatalog::class),
        ), true);
        $container->share(StudioAuthoringHostPort::class, static fn (
            Container $container,
        ): StudioAuthoringHostPort => new StudioAuthoringHostPort(
            self::service($container, ContentStudioAuthoringService::class),
            self::service($container, StudioDocumentSchemaRegistry::class),
        ), true);
        $container->share(ContentStudioAuthoringLaunchResolver::class, static fn (
            Container $container,
        ): ContentStudioAuthoringLaunchResolver => new ContentStudioAuthoringLaunchResolver(
            self::service($container, StudioContextualAuthoringAvailability::class),
            self::service($container, StudioContextualAuthoringConfigurationProvider::class),
        ), true);
        $container->share(
            StudioHostSessionRepository::class,
            static fn (Container $container): StudioHostSessionRepository =>
                new DoctrineStudioHostSessionRepository(
                    self::service($container, Connection::class),
                    self::service($container, TableNames::class),
                ),
            true,
        );
        $container->share(
            StudioResourceContextKeyFactory::class,
            new RandomStudioResourceContextKeyFactory(),
            true,
        );
        $container->share(ContentStudioAuthoringContextAuthority::class, static fn (
            Container $container,
        ): ContentStudioAuthoringContextAuthority => new ContentStudioAuthoringContextAuthority(
            self::service($container, ContentStudioAuthoringContextRepository::class),
            self::service($container, StudioResourceContextKeyFactory::class),
            self::service($container, ContentStudioAuthoringTargetResolver::class),
            self::service($container, ContentModelService::class),
            self::service($container, ContentService::class),
            self::service($container, ClockInterface::class),
            $configuration->administratorSessionSeconds,
        ), true);
        $container->share(StudioHostSessionAuthority::class, static fn (
            Container $container,
        ): StudioHostSessionAuthority => new StudioHostSessionAuthority(
            self::service($container, AuthorizationGateway::class),
            self::service($container, StudioHostSessionRepository::class),
            self::service($container, StudioResourceContextKeyFactory::class),
            self::service($container, StudioPublishedTheme::class),
        ), true);
        $container->share(DoctrineStudioHostStorage::class, static fn (
            Container $container,
        ): DoctrineStudioHostStorage => new DoctrineStudioHostStorage(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->alias(StudioArtifactRepository::class, DoctrineStudioHostStorage::class);
        $container->alias(StudioMutationReplayRepository::class, DoctrineStudioHostStorage::class);
        $container->alias(StudioRecoveryRepository::class, DoctrineStudioHostStorage::class);
        $container->share(StudioMutationOutcomeCodec::class, new SodiumStudioMutationOutcomeCodec(hash_hkdf(
            'sha256',
            $configuration->secret,
            32,
            'kumwe-studio-mutation-outcome-v1',
        )), true);
        $container->share(StudioArtifactAdmission::class, static fn (
            Container $container,
        ): StudioArtifactAdmission => new StudioArtifactAdmission(
            self::service($container, StudioDocumentSchemaRegistry::class),
        ), true);
        $container->share(StudioArtifactHostPort::class, static fn (
            Container $container,
        ): StudioArtifactHostPort => new StudioArtifactHostPort(
            self::service($container, StudioArtifactRepository::class),
            self::service($container, StudioArtifactAdmission::class),
            self::service($container, StudioArtifactPublicationGuard::class),
        ), true);
        $container->share(StudioRecoveryHostPort::class, static fn (
            Container $container,
        ): StudioRecoveryHostPort => new StudioRecoveryHostPort(
            self::service($container, StudioRecoveryRepository::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(DoctrineStudioPreviewRepository::class, static fn (
            Container $container,
        ): DoctrineStudioPreviewRepository => new DoctrineStudioPreviewRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->alias(StudioPreviewGrantRepository::class, DoctrineStudioPreviewRepository::class);
        $container->alias(StudioPreviewSequenceRepository::class, DoctrineStudioPreviewRepository::class);
        $container->share(StudioPreviewDraftSource::class, static fn (
            Container $container,
        ): StudioPreviewDraftSource => new DoctrineStudioPreviewDraftSource(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, StudioDocumentSchemaRegistry::class),
        ), true);
        $container->share(StudioPreviewBindingSource::class, static fn (
            Container $container,
        ): StudioPreviewBindingSource => new ContentStudioPreviewBindingSource(
            self::service($container, StudioContentProjectionService::class),
        ), true);
        $container->share(StudioPreviewBindingResolver::class, new StudioPreviewBindingResolver(), true);
        $container->share(StudioContentFieldBlockRenderer::class, new StudioContentFieldBlockRenderer(), true);
        $container->share(StudioBlockRendererRuntime::class, static fn (
            Container $container,
        ): StudioBlockRendererRuntime => new StudioBlockRendererRuntime(
            self::service($container, ExtensionContributionRegistrySet::class),
            self::service($container, StudioContentFieldBlockRenderer::class),
            self::service($container, StudioCoreCatalog::class),
        ), true);
        $container->share(StudioPublishedCompositionGuard::class, static fn (
            Container $container,
        ): StudioPublishedCompositionGuard => new StudioPublishedCompositionGuard(
            self::service($container, StudioArtifactAdmission::class),
            self::service($container, ContentModelRepository::class),
            self::service($container, StudioPublishedTheme::class),
            self::service($container, StudioBlockRendererRuntime::class),
            self::service($container, ExtensionContributionRegistrySet::class),
        ), true);
        $container->alias(StudioArtifactPublicationGuard::class, StudioPublishedCompositionGuard::class);
        $container->share(CanonicalStudioPublishedContentRenderer::class, static fn (
            Container $container,
        ): CanonicalStudioPublishedContentRenderer => new CanonicalStudioPublishedContentRenderer(
            self::service($container, ContentProjectionBindingRepository::class),
            self::service($container, StudioArtifactRepository::class),
            self::service($container, StudioPublishedCompositionGuard::class),
            self::service($container, ContentStudioProjector::class),
            self::service($container, StudioBlockRendererRuntime::class),
            self::service($container, StudioPreviewBindingResolver::class),
        ), true);
        $container->alias(StudioPublishedContentRenderer::class, CanonicalStudioPublishedContentRenderer::class);
        $container->share(StudioPreviewActivityRecorder::class, static fn (
            Container $container,
        ): StudioPreviewActivityRecorder => new StructuredLogStudioPreviewActivityRecorder(
            self::service($container, LoggerInterface::class),
        ), true);
        $container->share(StudioPreviewRenderer::class, static fn (
            Container $container,
        ): StudioPreviewRenderer => new CanonicalStudioPreviewRenderer(
            self::service($container, ContentPageRenderService::class),
            self::service($container, StudioBlockRendererRuntime::class),
            self::service($container, StudioPreviewBindingResolver::class),
            self::service($container, StudioPublishedTheme::class),
            $configuration->publicSite,
        ), true);
        $container->share(StudioPreviewTransportGuard::class, static fn (
            Container $container,
        ): StudioPreviewTransportGuard => new StudioPreviewTransportGuard(
            $configuration->baseUrl,
            self::service($container, StudioPreviewSequenceRepository::class),
            new NativeStudioPreviewSequenceWaiter(),
        ), true);
        $container->share(StudioPreviewHostPort::class, static fn (
            Container $container,
        ): StudioPreviewHostPort => new StudioPreviewHostPort(
            self::service($container, StudioPreviewDraftSource::class),
            self::service($container, StudioPreviewBindingSource::class),
            self::service($container, StudioPreviewRenderer::class),
            self::service($container, StudioPreviewGrantRepository::class),
            self::service($container, StudioPreviewTransportGuard::class),
            self::service($container, StudioPreviewActivityRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(StudioModelHostPort::class, static fn (
            Container $container,
        ): StudioModelHostPort => new StudioModelHostPort(
            self::service($container, StudioContentProjectionService::class),
        ), true);
        $container->share(ContentStudioResourceSearchProvider::class, static fn (
            Container $container,
        ): ContentStudioResourceSearchProvider => new ContentStudioResourceSearchProvider(
            self::service($container, ContentService::class),
        ), true);
        $container->share(StudioResourceHostPort::class, static fn (
            Container $container,
        ): StudioResourceHostPort => new StudioResourceHostPort([
            self::service($container, ContentStudioResourceSearchProvider::class),
        ]), true);
        $container->share(StudioLocalizationHostPort::class, static fn (
            Container $container,
        ): StudioLocalizationHostPort => new StudioLocalizationHostPort(
            self::service($container, MessageCatalogueRepository::class),
            self::service($container, MessageOverrideRepository::class),
            self::service($container, ActiveLocale::class),
            self::service($container, SupportedLocales::class),
        ), true);
        $container->share(StudioTelemetryHostPort::class, static fn (
            Container $container,
        ): StudioTelemetryHostPort => new StudioTelemetryHostPort(
            self::service($container, LoggerInterface::class),
        ), true);
        $container->share(MediaStorage::class, new FilesystemMediaStorage(
            $root . '/storage/media',
            $root . '/resources/media',
        ), true);
        $container->share(MediaService::class, static fn (Container $container): MediaService => new MediaService(
            self::service($container, MediaStorage::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            $configuration->maxBodyBytes,
            self::service($container, TransactionManager::class),
        ), true);
        $container->share(
            StudioMediaUploadRepository::class,
            static fn (Container $container): StudioMediaUploadRepository =>
                new DoctrineStudioMediaUploadRepository(
                    self::service($container, Connection::class),
                    self::service($container, TableNames::class),
                ),
            true,
        );
        $container->share(
            StudioMediaStagingStorage::class,
            new FilesystemStudioMediaStagingStorage($root . '/storage/studio-media/uploads'),
            true,
        );
        $container->share(
            StudioExternalAddressResolver::class,
            new NativeStudioExternalAddressResolver(),
            true,
        );
        $container->share(
            StudioPinnedHttpTransport::class,
            new SocketStudioPinnedHttpTransport($root . '/storage/studio-media/external'),
            true,
        );
        $container->share(
            StudioMediaSignatureVerifier::class,
            new FinfoStudioMediaSignatureVerifier(),
            true,
        );
        $container->share(StudioExternalUrlPolicy::class, new StudioExternalUrlPolicy(), true);
        $container->share(StudioMediaUploadPolicy::class, new StudioMediaUploadPolicy(
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'application/pdf'],
            $configuration->maxBodyBytes,
            false,
        ), true);
        $container->share(StudioExternalMediaFetcher::class, static fn (
            Container $container,
        ): StudioExternalMediaFetcher => new StudioExternalMediaFetcher(
            self::service($container, StudioExternalUrlPolicy::class),
            self::service($container, StudioExternalAddressResolver::class),
            self::service($container, StudioPinnedHttpTransport::class),
            self::service($container, StudioMediaSignatureVerifier::class),
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'application/pdf'],
            $configuration->maxBodyBytes,
        ), true);
        $container->share(StudioMediaAssetProjector::class, new StudioMediaAssetProjector(), true);
        $container->share(StudioMediaCursorCodec::class, new StudioMediaCursorCodec(hash_hkdf(
            'sha256',
            $configuration->secret,
            32,
            'kumwe-studio-media-cursor-v1',
        )), true);
        $container->share(StudioMediaGrantToken::class, new StudioMediaGrantToken(hash_hkdf(
            'sha256',
            $configuration->secret,
            32,
            'kumwe-studio-media-upload-grant-v1',
        )), true);
        $container->share(StudioMediaService::class, static fn (
            Container $container,
        ): StudioMediaService => new StudioMediaService(
            self::service($container, MediaService::class),
            self::service($container, StudioMediaUploadRepository::class),
            self::service($container, StudioMediaStagingStorage::class),
            self::service($container, StudioMediaUploadPolicy::class),
            self::service($container, StudioMediaSignatureVerifier::class),
            self::service($container, StudioExternalMediaFetcher::class),
            self::service($container, StudioMediaAssetProjector::class),
            self::service($container, StudioMediaCursorCodec::class),
            self::service($container, StudioMediaGrantToken::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            $configuration->baseUrl,
        ), true);
        $container->share(
            StudioMediaOperations::class,
            static fn (Container $container): StudioMediaOperations =>
                self::service($container, StudioMediaService::class),
            true,
        );
        $container->share(StudioMediaHostPort::class, static fn (
            Container $container,
        ): StudioMediaHostPort => new StudioMediaHostPort(
            self::service($container, StudioMediaOperations::class),
        ), true);
        $container->share(StudioProducerHostFactory::class, static fn (
            Container $container,
        ): StudioProducerHostFactory => new StudioProducerHostFactory(
            self::service($container, StudioHostSessionAuthority::class),
            self::service($container, TransactionManager::class),
            self::service($container, TransactionState::class),
            self::service($container, StudioMutationReplayRepository::class),
            self::service($container, StudioMutationOutcomeCodec::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, StudioMediaOperations::class),
            self::service($container, StudioArtifactHostPort::class),
            self::service($container, StudioLocalizationHostPort::class),
            self::service($container, StudioMediaHostPort::class),
            self::service($container, StudioModelHostPort::class),
            self::service($container, StudioPreviewHostPort::class),
            self::service($container, StudioRecoveryHostPort::class),
            self::service($container, StudioResourceHostPort::class),
            self::service($container, StudioTelemetryHostPort::class),
            self::service($container, StudioAuthoringHostPort::class),
        ), true);
        $container->share(NavigationRepository::class, static fn (Container $container): NavigationRepository =>
            new DoctrineNavigationRepository(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(PublicNavigation::class, static fn (Container $container): PublicNavigation =>
            new PublicNavigation(
                self::service($container, NavigationRepository::class),
                self::service($container, ResourceSiteOwnership::class),
                SiteContext::fromString($configuration->publicSite),
            ), true);
        $container->share(NavigationService::class, static fn (Container $container): NavigationService =>
            new NavigationService(
                self::service($container, NavigationRepository::class),
                self::service($container, AuditRecorder::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, ContentService::class),
            ), true);
        $container->share(AccessControlRepository::class, static fn (
            Container $container,
        ): AccessControlRepository => new DoctrineAccessControlRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(TokenDelegationPreauthorizer::class, static fn (
            Container $container,
        ): TokenDelegationPreauthorizer => new TokenDelegationPreauthorizer(
            self::service($container, AccessControlRepository::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(TokenRotationPreauthorizer::class, static fn (
            Container $container,
        ): TokenRotationPreauthorizer => new TokenRotationPreauthorizer(
            self::service($container, AccessControlRepository::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TokenDelegationPreauthorizer::class),
        ), true);
        $container->share(AccessControlService::class, static fn (Container $container): AccessControlService =>
            new AccessControlService(
                self::service($container, AccessControlRepository::class),
                self::service($container, PasswordHasher::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, HighImpactCredentialGuard::class),
                self::service($container, StepUpCredentialStore::class),
                self::service($container, AdministratorSessionStore::class),
            ), true);
        $container->share(DoctrineSiteSettings::class, static fn (
            Container $container,
        ): DoctrineSiteSettings => new DoctrineSiteSettings(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, ContentService::class),
        ), true);
        $container->share(SiteSettings::class, static fn (Container $container): SiteSettings =>
            new CachedSiteSettings(
                self::service($container, DoctrineSiteSettings::class),
                self::service($container, RedisRuntime::class),
                self::service($container, LoggerInterface::class),
            ), true);
        $container->share(PublicPageLocator::class, static fn (Container $container): PublicPageLocator =>
            new PublicPageLocator(
                self::service($container, ContentService::class),
                self::service($container, SiteSettings::class),
                self::service($container, PublicNavigation::class),
                SiteContext::fromString($configuration->publicSite),
            ), true);
        $container->share(TranslationGroupPresenter::class, static fn (
            Container $container,
        ): TranslationGroupPresenter => new TranslationGroupPresenter(
            self::service($container, TranslationGroupRepository::class),
            self::service($container, ContentService::class),
            self::service($container, PublicPageLocator::class),
            self::service($container, ActiveLocale::class),
            self::service($container, ClockInterface::class),
            SiteContext::fromString($configuration->publicSite),
        ), true);
        $container->share(JobExecutionScope::class, new JobExecutionScope(), true);
        $container->share(JobQueue::class, static fn (Container $container): JobQueue =>
            new DoctrineJobQueue(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                $configuration->release,
                self::service($container, AuthorizationGateway::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, JobExecutionScope::class),
                self::service($container, QueueRuntimePolicyCatalog::class),
            ), true);
        $container->share(DoctrineScheduler::class, static fn (
            Container $container,
        ): DoctrineScheduler => new DoctrineScheduler(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, ResourceSiteOwnership::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            SystemPrincipal::issue($provenance, SystemIdentity::Scheduler),
            self::service($container, JobExecutionScope::class),
            self::service($container, ScheduleRuntimeSynchronizer::class),
            self::service($container, QueueRuntimePolicyCatalog::class),
        ), true);
        $container->alias(Scheduler::class, DoctrineScheduler::class);
        $container->alias(ScheduleRepository::class, DoctrineScheduler::class);
        $container->share(AutomationManagementService::class, static fn (
            Container $container,
        ): AutomationManagementService => new AutomationManagementService(
            self::service($container, ScheduleRepository::class),
            self::service($container, JobQueue::class),
            self::service($container, JobHandlerRegistry::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, JobExecutionScope::class),
            self::service($container, QueueRuntimeOperations::class),
        ), true);
        $container->share(MigrationPlan::class, static fn (Container $container): MigrationPlan =>
            new MigrationPlan(
                [
                    new CoreSchemaMigration(self::service($container, TableNames::class)),
                    new ApplicationAuthorizationMigration(self::service($container, TableNames::class)),
                    new JobRecoveryMigration(self::service($container, TableNames::class)),
                    new IdempotencyLeaseNullabilityMigration(self::service($container, TableNames::class)),
                    new AuthorizationRecoveryIntegrationMigration(self::service($container, TableNames::class)),
                    new TokenAndTrustLifecycleMigration(self::service($container, TableNames::class)),
                    new SiteAutomationContextMigration(self::service($container, TableNames::class)),
                    new IsolateThemeSurfacesMigration(self::service($container, TableNames::class)),
                    new InstallationGlobalAutomationMigration(self::service($container, TableNames::class)),
                    new ContentModelRuntimeMigration(self::service($container, TableNames::class)),
                    new DynamicSiteContentMigration(self::service($container, TableNames::class)),
                    new DatabaseDrivenPresentationMigration(self::service($container, TableNames::class)),
                    new ExtensionContributionCatalogMigration(self::service($container, TableNames::class)),
                    new BusinessDefinitionCatalogMigration(self::service($container, TableNames::class)),
                    new BusinessTransactionalRuntimeMigration(self::service($container, TableNames::class)),
                    new BusinessRecordIdempotencyRetentionMigration(self::service($container, TableNames::class)),
                    new BusinessSecurityPortalMigration(self::service($container, TableNames::class)),
                    new BusinessIntegrationSdkMigration(self::service($container, TableNames::class)),
                    new DemoProfileProvenanceMigration(self::service($container, TableNames::class)),
                    new ContentModelIdentifierCollationMigration(self::service($container, TableNames::class)),
                    new InterfacePresentationPreferenceMigration(self::service($container, TableNames::class)),
                    new DocumentContentTypesMigration(self::service($container, TableNames::class)),
                    new MenuPresentationBindingMigration(self::service($container, TableNames::class)),
                    new AuditTamperEvidenceMigration(self::service($container, TableNames::class)),
                    new RecordEncryptionKeyRingMigration(self::service($container, TableNames::class)),
                    new CredentialLifecycleMigration(self::service($container, TableNames::class)),
                    new BusinessNumberSequenceMigration(self::service($container, TableNames::class)),
                    new ExtensionSupplyChainMigration(self::service($container, TableNames::class)),
                    new ResourceOwnershipPortabilityMigration(self::service($container, TableNames::class)),
                    new BusinessRecordHistoryWindowMigration(self::service($container, TableNames::class)),
                    new ResourceOwnershipScopeMigration(self::service($container, TableNames::class)),
                    new InterfaceMessageOverrideMigration(self::service($container, TableNames::class)),
                    new ConstraintNameIsolationCompatibilityMigration(
                        self::service($container, TableNames::class),
                    ),
                    new MultilingualContentMigration(self::service($container, TableNames::class)),
                    new TranslationGroupSiteOwnershipMigration(self::service($container, TableNames::class)),
                    new ConstraintNameIsolationPortabilityMigration(self::service($container, TableNames::class)),
                    new PeriodPostingLockMigration(self::service($container, TableNames::class)),
                    new NumberSequenceIdentityMigration(self::service($container, TableNames::class)),
                    new StudioContentProjectionMigration(self::service($container, TableNames::class)),
                    new IndexNameIsolationMigration(self::service($container, TableNames::class)),
                    new StudioHostSessionMigration(self::service($container, TableNames::class)),
                    new StudioArtifactRecoveryMigration(self::service($container, TableNames::class)),
                    new StudioPreviewGrantMigration(self::service($container, TableNames::class)),
                    new StudioMediaUploadMigration(self::service($container, TableNames::class)),
                    new StudioContentAuthoringContextMigration(self::service($container, TableNames::class)),
                    new StudioContentAuthoringContextRetentionMigration(self::service($container, TableNames::class)),
                ],
                self::acceptedHistoricalChecksums(),
            ), true);
        $container->share(MigrationRunner::class, static fn (Container $container): MigrationRunner =>
            new MigrationRunner(
                database: self::service($container, Connection::class),
                repository: self::service($container, MigrationRepository::class),
                lock: self::service($container, MigrationLock::class),
                transactions: self::service($container, TransactionManager::class),
                plan: self::service($container, MigrationPlan::class),
                authorization: self::service($container, AuthorizationGateway::class),
                nonTransactionalRecovery: self::service($container, NonTransactionalMigrationRecovery::class),
                collation: new SchemaCollationConvergence(
                    self::service($container, Connection::class),
                    self::service($container, TableNames::class),
                ),
            ), true);
        $container->share(ReadinessProbe::class, static fn (Container $container): ReadinessProbe =>
            new ReadinessProbe(
                database: self::service($container, Connection::class),
                logger: self::service($container, LoggerInterface::class),
                tables: self::service($container, TableNames::class),
                migrations: self::service($container, MigrationRepository::class),
                plan: self::service($container, MigrationPlan::class),
                recovery: self::service($container, NonTransactionalMigrationRecovery::class),
                redis: self::service($container, RedisRuntime::class),
                trust: self::service($container, TrustStore::class),
                runtime: self::service($container, ExtensionRuntimeMapCompiler::class),
                materialization: self::service($container, RuntimeMaterializationState::class),
            ), true);
    }

    /**
     * Register the interface-translation services every surface resolves its wording through.
     *
     * This runs before the extension registrar and the HTTP registrar, because both reach for a
     * translator during composition: a contributed automation form is compiled while extensions are
     * registered, and the three Twig environments and the console output are built later. ADR 0002
     * fixes the shape: XLIFF authored, compiled to plain PHP, formatted by ICU, resolved through
     * core, extension, site and organization.
     *
     * @param   Container                 $container      Container being composed.
     * @param   ApplicationConfiguration  $configuration  Resolved application configuration.
     * @param   string                    $root           Absolute path of the repository root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerLocalization(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
    ): void {
        $container->share(SupportedLocales::class, new SupportedLocales(), true);
        $container->share(MessagePatternFormatter::class, new IntlMessagePatternFormatter(), true);
        $container->alias(MessagePatternValidator::class, MessagePatternFormatter::class);
        $container->share(MessageCatalogueRepository::class, static fn (
            Container $container,
        ): MessageCatalogueRepository => new CompiledMessageCatalogueRepository(
            $root . '/resources/localization/compiled',
            self::service($container, ActiveExtensionSet::class)->catalogueDirectories(),
        ), true);
        $container->share(DoctrineMessageOverrideRepository::class, static fn (
            Container $container,
        ): DoctrineMessageOverrideRepository => new DoctrineMessageOverrideRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->alias(MessageOverrideRepository::class, DoctrineMessageOverrideRepository::class);
        $container->alias(MessageOverrideStore::class, DoctrineMessageOverrideRepository::class);
        $container->share(MessageOverrideService::class, static fn (
            Container $container,
        ): MessageOverrideService => new MessageOverrideService(
            self::service($container, MessageOverrideStore::class),
            self::service($container, MessageCatalogueRepository::class),
            self::service($container, SupportedLocales::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, MessagePatternValidator::class),
            self::service($container, Translator::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(ActiveLocale::class, static fn (Container $container): ActiveLocale =>
            new ActiveLocale(self::service($container, SupportedLocales::class)), true);
        $container->share(SiteDefaultLocale::class, static fn (Container $container): SiteDefaultLocale =>
            new SiteDefaultLocale(
                self::service($container, SiteSettings::class),
                self::service($container, SupportedLocales::class),
            ), true);
        $container->share(LocaleNegotiator::class, static fn (Container $container): LocaleNegotiator =>
            new LocaleNegotiator(
                self::service($container, SupportedLocales::class),
                self::service($container, SiteDefaultLocale::class),
            ), true);
        $container->share(Translator::class, static fn (Container $container): Translator =>
            new CatalogueTranslator(
                self::service($container, MessageCatalogueRepository::class),
                self::service($container, MessageOverrideRepository::class),
                self::service($container, MessagePatternFormatter::class),
                self::service($container, ActiveLocale::class),
                self::service($container, SupportedLocales::class),
            ), true);
        $container->share(TranslationTwigExtension::class, static fn (
            Container $container,
        ): TranslationTwigExtension => new TranslationTwigExtension(
            self::service($container, Translator::class),
            self::service($container, ActiveLocale::class),
        ), true);
        $container->share(LocaleNegotiationMiddleware::class, static fn (
            Container $container,
        ): LocaleNegotiationMiddleware => new LocaleNegotiationMiddleware(
            self::service($container, LocaleNegotiator::class),
            self::service($container, ActiveLocale::class),
            $configuration->publicSite,
        ), true);
        $container->share(TranslationScopeMiddleware::class, static fn (
            Container $container,
        ): TranslationScopeMiddleware => new TranslationScopeMiddleware(
            self::service($container, ActiveLocale::class),
        ), true);
    }

    /**
     * Register the presentation, routing and PSR-15 runner services, then share the application.
     *
     * Twig environments are built through `IsolatedTwigEnvironmentFactory` so a site template and an
     * administrator template can never read each other's files. The shared `Application` is
     * registered last because its factory pipes the middleware and declares every route, and so must
     * see the middleware and handlers this method registers on the way.
     *
     * @param   Container                 $container       Container being composed.
     * @param   ApplicationConfiguration  $configuration   Boot configuration for base URL, site and caching.
     * @param   string                    $root            Absolute path of the repository root.
     * @param   string                    $routeCacheFile  Kernel-specific FastRoute cache path.
     * @param   object                    $kernelProof     Private provenance for extension route renderers.
     * @param   bool                      $portalEnabled   Whether to register the ordinary-user portal runtime.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerHttp(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
        string $routeCacheFile,
        object $kernelProof,
        bool $portalEnabled,
    ): void {
        $container->share(
            ViteAssetManifest::class,
            new ViteAssetManifest($root . '/public/assets/build/.vite/manifest.json'),
            true,
        );
        $container->share(ContentFormPresenter::class, new ContentFormPresenter(), true);
        $container->share(ContentFormDataMapper::class, new ContentFormDataMapper(), true);
        $container->share(ContentModelFormMapper::class, new ContentModelFormMapper(), true);
        $container->share(ContentModelFormPresenter::class, new ContentModelFormPresenter(), true);
        $container->share(SitePresentationFormMapper::class, static fn (
            Container $container,
        ): SitePresentationFormMapper => new SitePresentationFormMapper(
            self::service($container, Translator::class),
        ), true);
        $container->share(RichTextFormatter::class, new RichTextFormatter(), true);
        $container->share(ContentPresenter::class, static fn (Container $container): ContentPresenter =>
            new ContentPresenter(self::service($container, RichTextFormatter::class)), true);
        $container->share(ResponseFactoryInterface::class, new ResponseFactory(), true);
        $container->share(StreamFactoryInterface::class, new StreamFactory(), true);
        $container->share(IsolatedTwigEnvironmentFactory::class, static fn (
            Container $container,
        ): IsolatedTwigEnvironmentFactory => new IsolatedTwigEnvironmentFactory(
            self::service($container, ActiveExtensionSet::class),
            $root . '/templates',
            $root . '/storage/cache/twig',
            $configuration->isProduction(),
            self::service($container, TranslationTwigExtension::class),
        ), true);
        $container->share(SiteTwigEnvironment::class, static fn (
            Container $container,
        ): SiteTwigEnvironment => self::service($container, IsolatedTwigEnvironmentFactory::class)->site(
            SiteContext::fromString($configuration->publicSite),
        ), true);
        $container->share(AdministratorTwigEnvironment::class, static fn (
            Container $container,
        ): AdministratorTwigEnvironment => self::service(
            $container,
            IsolatedTwigEnvironmentFactory::class,
        )->administrator(), true);
        $container->share(RecoveryAdministratorTwigEnvironment::class, static fn (
            Container $container,
        ): RecoveryAdministratorTwigEnvironment => self::service(
            $container,
            IsolatedTwigEnvironmentFactory::class,
        )->recoveryAdministrator(), true);
        $container->share(SiteRenderer::class, static fn (Container $container): SiteRenderer =>
            new SiteRenderer(
                self::service($container, SiteTwigEnvironment::class),
                self::service($container, ViteAssetManifest::class),
                $configuration->baseUrl,
            ), true);
        $container->share(ContentPageRenderService::class, static fn (
            Container $container,
        ): ContentPageRenderService => new ContentPageRenderService(
            self::service($container, SiteSettings::class),
            self::service($container, SiteRenderer::class),
        ), true);
        $container->share(RecoveryAdministratorRenderer::class, static fn (
            Container $container,
        ): RecoveryAdministratorRenderer => new RecoveryAdministratorRenderer(
            self::service($container, RecoveryAdministratorTwigEnvironment::class),
        ), true);
        $container->share(AdministratorRenderer::class, static fn (Container $container): AdministratorRenderer =>
            new AdministratorRenderer(
                self::service($container, AdministratorTwigEnvironment::class),
                self::service($container, RecoveryAdministratorRenderer::class),
                self::service($container, AdministratorNavigationRegistry::class),
                self::service($container, ViteAssetManifest::class),
                self::service($container, AdministratorViewRegistry::class),
                $kernelProof,
            ), true);
        if ($portalEnabled) {
            $container->share(
                PortalTwigEnvironmentFactory::class,
                static fn (Container $container): PortalTwigEnvironmentFactory => new PortalTwigEnvironmentFactory(
                    $configuration->isProduction(),
                    self::service($container, TranslationTwigExtension::class),
                ),
                true,
            );
            $container->share(PortalRenderer::class, static fn (Container $container): PortalRenderer =>
                new PortalRenderer(
                    self::service($container, PortalTwigEnvironmentFactory::class)->create(
                        $root . '/templates',
                        self::service($container, ActiveExtensionSet::class)->portalTemplatePaths(),
                        $root . '/storage/cache/twig/portal',
                    ),
                    self::service($container, PortalNavigationRegistry::class),
                    self::service($container, PortalTemplateRegistry::class),
                    new GeneratedBusinessPortalNavigationVisibility(
                        self::service($container, BusinessSurfaceCatalog::class),
                        self::service($container, PortalExecutionContextFactory::class),
                        self::service($container, ReportService::class),
                    ),
                    self::service($container, ViteAssetManifest::class),
                    $kernelProof,
                ), true);
        }
        $container->share(RouterInterface::class, static fn (): RouterInterface =>
            new FastRouteRouter(null, null, [
                'cache_enabled' => $configuration->isProduction(),
                'cache_file' => $routeCacheFile,
            ]), true);
        $container->share(RouteCollector::class, static fn (Container $container): RouteCollector =>
            new RouteCollector(self::service($container, RouterInterface::class), true), true);
        $container->alias(RouteCollectorInterface::class, RouteCollector::class);
        $container->share(MiddlewareContainer::class, static fn (Container $container): MiddlewareContainer =>
            new MiddlewareContainer($container), true);
        $container->share(MiddlewareFactory::class, static fn (Container $container): MiddlewareFactory =>
            new MiddlewareFactory(self::service($container, MiddlewareContainer::class)), true);
        $container->alias(MiddlewareFactoryInterface::class, MiddlewareFactory::class);
        $container->share(MiddlewarePipeInterface::class, new MiddlewarePipe(), true);
        $container->share(EmitterInterface::class, static function (): EmitterInterface {
            $emitter = new EmitterStack();
            $emitter->push(new SapiEmitter());

            return $emitter;
        }, true);
        $container->share(ServerRequestErrorResponseGenerator::class, static function (
            Container $container,
        ): ServerRequestErrorResponseGenerator {
            return new ServerRequestErrorResponseGenerator(
                self::service($container, ResponseFactoryInterface::class),
                false,
            );
        }, true);
        $container->share(RequestHandlerRunnerInterface::class, static function (
            Container $container,
        ): RequestHandlerRunnerInterface {
            return new RequestHandlerRunner(
                self::service($container, MiddlewarePipeInterface::class),
                self::service($container, EmitterInterface::class),
                static fn () => ServerRequestFactory::fromGlobals(),
                self::service($container, ServerRequestErrorResponseGenerator::class),
            );
        }, true);

        $this->registerMiddleware($container, $configuration, $portalEnabled);
        $this->registerHandlers($container, $configuration, $root, $portalEnabled);
        $container->share(Application::class, function (Container $container) use ($portalEnabled): Application {
            $application = new Application(
                self::service($container, MiddlewareFactoryInterface::class),
                self::service($container, MiddlewarePipeInterface::class),
                self::service($container, RouteCollectorInterface::class),
                self::service($container, RequestHandlerRunnerInterface::class),
            );
            $this->configureApplication($application, $container, $portalEnabled);

            return $application;
        }, true);
    }

    /**
     * Register the extension, trust and business-schema graph, then materialise the active set.
     *
     * This is the one registrar that does work eagerly. It inspects the locally compiled runtime map
     * and, when the caller allows it and the map is trusted, executes each extension provider with a
     * fixed allow-list of core services rather than the container itself. A missing, untrusted or
     * unverified map degrades to an empty `ActiveExtensionSet` instead of failing the boot, so a
     * damaged installation still answers on its recovery surfaces.
     *
     * @param   Container                 $container      Container being composed.
     * @param   ApplicationConfiguration  $configuration  Boot configuration for signing keys and identities.
     * @param   string                    $root           Absolute path of the repository root.
     * @param   object                    $kernelProof    Private provenance for worker integration contexts.
     * @param   bool                      $loadRuntime    Whether providers named by the map may execute.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a trusted map is loaded but its structure or an entry is invalid.
     *
     * @since   2.0.0
     */
    private function registerExtensions(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
        object $kernelProof,
        bool $loadRuntime,
    ): void {
        $mapFile = $root . '/storage/cache/extensions.json';
        $extensionRoot = $root . '/extensions';
        $publicAssetRoot = $root . '/public/assets/extensions';
        $keyRing = new RuntimePublicationKeyRing(
            $configuration->runtimeSigningKeyId,
            $configuration->runtimeSigningKey,
            $configuration->runtimePreviousSigningKeys,
        );
        $schemaObservers = new DeferredBusinessSchemaObserver(
            static fn (): PublishedDefinitionSchemaObserver => self::service(
                $container,
                PublishedDefinitionSchemaObserver::class,
            ),
            static fn (): BusinessSchemaLifecycleObserver => self::service(
                $container,
                BusinessSchemaLifecycleObserver::class,
            ),
        );
        $coreOpenApiJson = file_get_contents($root . '/api/openapi/kumwe-v1.json');
        if ($coreOpenApiJson === false) {
            throw new RuntimeException('The checked-in core OpenAPI contract cannot be read.');
        }
        $coreOpenApi = self::decodeOpenApiObject($coreOpenApiJson);
        $componentClaims = new OpenApiComponentClaimAdmission($coreOpenApi);
        $container->share(OpenApiComponentClaimAdmission::class, $componentClaims, true);
        $container->share(BusinessDefinitionContractAdmission::class, $componentClaims, true);
        $container->share(
            ExtensionActivationAdmission::class,
            new OpenApiExtensionActivationAdmission(
                $componentClaims,
                static fn (): BusinessDefinitionRepository => self::service(
                    $container,
                    BusinessDefinitionRepository::class,
                ),
            ),
            true,
        );
        $container->share(ArchiveContentReader::class, new ZipArchiveContentReader(), true);
        $container->share(PackageCodeConformance::class, new PackageCodeConformance(), true);
        $container->share(PackageEvidenceInspector::class, static fn (
            Container $container,
        ): PackageEvidenceInspector => new PackageEvidenceInspector(
            self::service($container, ArchiveContentReader::class),
            self::service($container, PackageCodeConformance::class),
        ), true);
        $container->share(
            PackageAdmissionPolicy::class,
            new PackageAdmissionPolicy($configuration->packageConformanceAdmission),
            true,
        );
        $container->share(ComponentScaffolder::class, new ComponentScaffolder(), true);
        $container->share(PackageInspector::class, new PackageInspector(), true);
        $container->share(DeterministicPackageBuilder::class, static fn (
            Container $container,
        ): DeterministicPackageBuilder => new DeterministicPackageBuilder(
            self::service($container, PackageInspector::class),
        ), true);
        $container->share(ProtectedSigningKeyReader::class, new ProtectedSigningKeyReader(), true);
        $container->share(PackageSigner::class, static fn (Container $container): PackageSigner =>
            new PackageSigner(
                self::service($container, ProtectedSigningKeyReader::class),
                self::service($container, PackageInspector::class),
            ), true);
        $container->share(StaticConformanceRunner::class, static fn (
            Container $container,
        ): StaticConformanceRunner => new StaticConformanceRunner(
            self::service($container, PackageInspector::class),
        ), true);
        $container->share(ExtensionMigrationRunner::class, static fn (
            Container $container,
        ): ExtensionMigrationRunner => new ExtensionMigrationRunner(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(PackageDefinitionSynchronizer::class, static fn (
            Container $container,
        ): PackageDefinitionSynchronizer => new DoctrinePackageDefinitionSynchronizer(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessDefinitionCompatibilityAnalyzer::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            $schemaObservers,
            $schemaObservers,
        ), true);
        $container->share(ExtensionRuntimeMapCompiler::class, static fn (
            Container $container,
        ): ExtensionRuntimeMapCompiler => new ExtensionRuntimeMapCompiler(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            $mapFile,
            $extensionRoot,
            $publicAssetRoot,
            self::service($container, ClockInterface::class),
            new RuntimeIdentity(
                $configuration->deploymentId,
                $configuration->replicaId,
                $configuration->processId,
                $configuration->instanceId,
            ),
            $keyRing,
            new RuntimeArtifactDigester(),
            3_600,
            300,
            self::service($container, LoggerInterface::class),
        ), true);
        $runtimeWithdrawal = new DeferredExtensionRuntimeWithdrawal();
        $container->share(ExtensionRuntimeWithdrawal::class, $runtimeWithdrawal, true);
        $container->share(TrustRuntimeInvalidator::class, static fn (Container $container): TrustRuntimeInvalidator =>
            self::service($container, ExtensionRuntimeMapCompiler::class), true);
        $container->share(ExtensionArtifactVerifier::class, new FilesystemExtensionArtifactVerifier(
            $extensionRoot,
        ), true);
        $container->share(TrustStore::class, static fn (Container $container): TrustStore => new TrustStore(
            self::service($container, TrustStoreRepository::class),
            self::service($container, PublicKeyPackageSignatureVerifier::class),
            self::service($container, ExtensionArtifactVerifier::class),
            self::service($container, TrustRuntimeInvalidator::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, AuthorizationGateway::class),
            $configuration->allowUnsignedLocalExtensions,
            self::service($container, PackageDefinitionSynchronizer::class),
            self::service($container, ExtensionRuntimeWithdrawal::class),
        ), true);
        $container->share(RevocationListVerifier::class, new SodiumRevocationListVerifier(), true);
        $container->share(RevocationFeedSource::class, new StreamRevocationFeedSource(), true);
        $container->share(RevocationFeedStateStore::class, static fn (
            Container $container,
        ): RevocationFeedStateStore => new DoctrineRevocationFeedStateStore(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(RevocationFeedSynchronizer::class, static fn (
            Container $container,
        ): RevocationFeedSynchronizer => new RevocationFeedSynchronizer(
            $configuration->revocationFeed,
            self::service($container, RevocationFeedSource::class),
            self::service($container, RevocationListVerifier::class),
            self::service($container, RevocationFeedStateStore::class),
            self::service($container, TrustStore::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, LoggerInterface::class),
        ), true);
        $contributionRegistries = new ExtensionContributionRegistrySet(
            self::service($container, TrustStore::class),
            authorizationPolicies: self::service($container, AuthorizationPolicyRegistry::class),
        );
        $container->share(ExtensionContributionRegistrySet::class, $contributionRegistries, true);
        $container->share(DoctrinePresentationPreferenceRepository::class, static fn (
            Container $container,
        ): DoctrinePresentationPreferenceRepository => new DoctrinePresentationPreferenceRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->alias(
            PresentationPreferenceRepository::class,
            DoctrinePresentationPreferenceRepository::class,
        );
        $container->share(DoctrinePresentationAccessGroupRepository::class, static fn (
            Container $container,
        ): DoctrinePresentationAccessGroupRepository => new DoctrinePresentationAccessGroupRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->alias(
            PresentationAccessGroupRepository::class,
            DoctrinePresentationAccessGroupRepository::class,
        );
        $container->share(
            PresentationPreferencePolicy::class,
            new RegisteredPresentationPreferencePolicy($contributionRegistries->interfaceSurfaces()),
            true,
        );
        $container->share(PresentationPreferenceResolver::class, static fn (
            Container $container,
        ): PresentationPreferenceResolver => new PresentationPreferenceResolver(
            self::service($container, PresentationPreferenceRepository::class),
            self::service($container, PresentationPreferencePolicy::class),
        ), true);
        $container->share(DashboardComposer::class, static fn (Container $container): DashboardComposer =>
            new DashboardComposer(
                self::service($container, PresentationPreferenceResolver::class),
                self::service($container, PresentationAccessGroupRepository::class),
            ), true);
        $container->share(PresentationPreferenceManager::class, static fn (
            Container $container,
        ): PresentationPreferenceManager => new PresentationPreferenceManager(
            self::service($container, PresentationPreferenceRepository::class),
            self::service($container, PresentationPreferencePolicy::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, TransactionManager::class),
            self::service($container, MembershipContextValidator::class),
            self::service($container, PresentationAccessGroupRepository::class),
        ), true);
        $container->share(DashboardPreferenceService::class, static fn (
            Container $container,
        ): DashboardPreferenceService => new DashboardPreferenceService(
            self::service($container, PresentationPreferenceManager::class),
            self::service($container, PresentationAccessGroupRepository::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(
            DashboardPreferenceFormPresenter::class,
            new DashboardPreferenceFormPresenter(),
            true,
        );
        $container->share(DashboardPreferenceFormDecoder::class, new DashboardPreferenceFormDecoder(), true);
        $container->share(DashboardPreferenceQueryDecoder::class, new DashboardPreferenceQueryDecoder(), true);
        $eventContracts = $contributionRegistries->validateIntegrationContributions();
        $container->share(EventContractRegistry::class, $eventContracts, true);
        $container->share(OutboxStore::class, static fn (Container $container): OutboxStore =>
            new DoctrineOutboxStore(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                self::service($container, EventContractRegistry::class),
            ), true);
        $container->share(InboxStore::class, static fn (Container $container): InboxStore =>
            new DoctrineInboxStore(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                self::service($container, EventContractRegistry::class),
                self::service($container, QueueRuntimePolicyCatalog::class),
            ), true);
        $container->share(ProcessManagerStore::class, static fn (Container $container): ProcessManagerStore =>
            new DoctrineProcessManagerStore(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(ProcessManagerService::class, static fn (
            Container $container,
        ): ProcessManagerService => new ProcessManagerService(
            self::service($container, ProcessManagerStore::class),
            self::service($container, EventContractRegistry::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(IntegrationOperationsService::class, static fn (
            Container $container,
        ): IntegrationOperationsService => new IntegrationOperationsService(
            self::service($container, OutboxStore::class),
            self::service($container, InboxStore::class),
            self::service($container, ProcessManagerStore::class),
            self::service($container, ProcessManagerService::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, ProjectionRuntime::class),
        ), true);
        $container->share(BusinessRecordMutationEventPublisher::class, static fn (
            Container $container,
        ): BusinessRecordMutationEventPublisher => new BusinessRecordMutationEventPublisher(
            self::service($container, EventContractRegistry::class),
            self::service($container, ExtensionContributionRegistrySet::class),
            self::service($container, OutboxStore::class),
            self::service($container, ExtensionExecutionGate::class),
        ), true);
        $container->share(FieldTypeRegistry::class, $contributionRegistries->fieldTypes(), true);
        $container->share(DoctrinePersistedFieldTypeDefinitionResolver::class, static fn (
            Container $container,
        ): DoctrinePersistedFieldTypeDefinitionResolver => new DoctrinePersistedFieldTypeDefinitionResolver(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, FieldTypeRegistry::class),
        ), true);
        $container->share(
            PhysicalNameCompiler::class,
            new PhysicalNameCompiler($configuration->database->tablePrefix),
            true,
        );
        $container->share(DefinitionPhysicalSchemaCompiler::class, static fn (
            Container $container,
        ): DefinitionPhysicalSchemaCompiler => new CanonicalDefinitionPhysicalSchemaCompiler(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, DoctrinePersistedFieldTypeDefinitionResolver::class),
            self::service($container, PhysicalNameCompiler::class),
        ), true);
        $container->share(BusinessSchemaPlanner::class, static fn (
            Container $container,
        ): BusinessSchemaPlanner => new BusinessSchemaPlanner(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, DefinitionPhysicalSchemaCompiler::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, BusinessSchemaPlanRepository::class),
            self::service($container, PhysicalSchemaGateway::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuditRecorder::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->alias(PublishedDefinitionSchemaObserver::class, BusinessSchemaPlanner::class);
        $container->share(BusinessSchemaRecordRepinGateway::class, static fn (
            Container $container,
        ): BusinessSchemaRecordRepinGateway => new DoctrineBusinessSchemaRecordRepinGateway(
            self::service($container, Connection::class),
            self::service($container, RecordValueCodec::class),
            self::service($container, RecordRuleValidator::class),
        ), true);
        $container->share(BusinessSchemaExecutor::class, static fn (
            Container $container,
        ): BusinessSchemaExecutor => new BusinessSchemaExecutor(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, DefinitionPhysicalSchemaCompiler::class),
            self::service($container, BusinessSchemaPlanRepository::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, BusinessSchemaRecoveryEvidenceRepository::class),
            self::service($container, BusinessSchemaExecutionLock::class),
            self::service($container, BusinessSchemaExecutionStateGuard::class),
            self::service($container, PhysicalSchemaGateway::class),
            self::service($container, BusinessSchemaRecordRepinGateway::class),
            self::service($container, BusinessSchemaEnvironment::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuditRecorder::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(BusinessSchemaLifecycleManager::class, static fn (
            Container $container,
        ): BusinessSchemaLifecycleManager => new BusinessSchemaLifecycleManager(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, DefinitionPhysicalSchemaCompiler::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, BusinessSchemaPlanRepository::class),
            self::service($container, PhysicalSchemaGateway::class),
        ), true);
        $container->alias(BusinessSchemaLifecycleObserver::class, BusinessSchemaLifecycleManager::class);
        $container->share(BusinessSchemaService::class, static fn (
            Container $container,
        ): BusinessSchemaService => new BusinessSchemaService(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessSchemaPlanner::class),
            self::service($container, BusinessSchemaExecutor::class),
            self::service($container, BusinessSchemaPlanRepository::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, BusinessSchemaRecoveryEvidenceRepository::class),
            self::service($container, BusinessSchemaEnvironment::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuditRecorder::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
        ), true);
        $keyRings = new ConfiguredSecretKeyRings(
            $configuration->secret,
            $configuration->recordEncryption->legacySecret,
            $configuration->recordEncryption->activeKeyId,
            $configuration->recordEncryption->activeKey,
            $configuration->recordEncryption->previousKeys,
        );
        $recordFingerprintKey = hash_hmac(
            'sha256',
            'kumwe:business-record:fingerprint:v1',
            $configuration->secret,
            true,
        );
        $recordCursorKey = hash_hmac(
            'sha256',
            'kumwe:business-record:cursor:v1',
            $configuration->secret,
            true,
        );
        $container->share(SecretKeyProvider::class, new KeyRingSecretKeyProvider($keyRings->records()), true);
        $container->share(SecretCipher::class, static fn (
            Container $container,
        ): SecretCipher => new KeyRingSecretCipher(
            self::service($container, SecretKeyProvider::class),
        ), true);
        $container->share(
            MutationPlanCipher::class,
            new KeyRingMutationPlanCipher(new KeyRingSecretKeyProvider($keyRings->mutationPlans())),
            true,
        );
        $container->share(RecordFingerprint::class, new RecordFingerprint($recordFingerprintKey), true);
        $container->share(RecordCursorCodec::class, new RecordCursorCodec($recordCursorKey), true);
        $container->share(RecordValueCodec::class, static fn (
            Container $container,
        ): RecordValueCodec => new RecordValueCodec(
            self::service($container, SecretCipher::class),
            self::service($container, FieldTypeRegistry::class),
        ), true);
        $container->share(RecordRuleValidator::class, static fn (
            Container $container,
        ): RecordRuleValidator => new RecordRuleValidator(
            self::service($container, RecordValueCodec::class),
        ), true);
        $container->share(BusinessRecordDefinitionResolver::class, static fn (
            Container $container,
        ): BusinessRecordDefinitionResolver => new InstalledBusinessRecordDefinitionResolver(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
        ), true);
        $container->share(DoctrineBusinessRecordQueryCompiler::class, static fn (
            Container $container,
        ): DoctrineBusinessRecordQueryCompiler => new DoctrineBusinessRecordQueryCompiler(
            self::service($container, Connection::class),
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, RecordValueCodec::class),
            self::service($container, RecordCursorCodec::class),
            self::service($container, BusinessRecordMutationFence::class),
        ), true);
        $container->share(BusinessRecordWriteRepository::class, static fn (
            Container $container,
        ): BusinessRecordWriteRepository => new DoctrineBusinessRecordWriteRepository(
            self::service($container, Connection::class),
            self::service($container, RecordValueCodec::class),
        ), true);
        $container->share(BusinessRecordReadRepository::class, static fn (
            Container $container,
        ): BusinessRecordReadRepository => new DoctrineBusinessRecordReadRepository(
            self::service($container, Connection::class),
            self::service($container, RecordValueCodec::class),
            self::service($container, RecordRuleValidator::class),
            self::service($container, DoctrineBusinessRecordQueryCompiler::class),
            self::service($container, RecordCursorCodec::class),
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, BusinessRecordMutationFence::class),
        ), true);
        $container->share(BusinessRecordRevisionRepository::class, static fn (
            Container $container,
        ): BusinessRecordRevisionRepository => new DoctrineBusinessRecordRevisionRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, DoctrineBusinessRecordQueryCompiler::class),
        ), true);
        $container->share(DocumentCommitTimingRecorder::class, new DocumentCommitTimingRecorder(), true);
        $container->share(BusinessRecordMutationPublication::class, static fn (
            Container $container,
        ): BusinessRecordMutationPublication => new BusinessRecordMutationPublication(
            self::service($container, BusinessRecordRevisionRepository::class),
            self::service($container, AuditRecorder::class),
            self::service($container, RecordFingerprint::class),
            self::service($container, BusinessRecordMutationEventPublisher::class),
            self::service($container, DocumentCommitTimingRecorder::class),
        ), true);
        $container->share(BusinessRecordIdempotencyRepository::class, static fn (
            Container $container,
        ): BusinessRecordIdempotencyRepository => new DoctrineBusinessRecordIdempotencyRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, RecordFingerprint::class),
        ), true);
        $container->share(BusinessRecordMutationFence::class, static fn (
            Container $container,
        ): BusinessRecordMutationFence => new DoctrineBusinessRecordMutationFence(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(RecordSecretRotation::class, static fn (
            Container $container,
        ): RecordSecretRotation => new DoctrineRecordSecretRotation(
            self::service($container, Connection::class),
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, BusinessRecordMutationFence::class),
            self::service($container, SecretCipher::class),
            self::service($container, SecretKeyProvider::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(BusinessRecordIdempotencyPurger::class, static fn (
            Container $container,
        ): BusinessRecordIdempotencyPurger => new BusinessRecordIdempotencyPurger(
            self::service($container, BusinessRecordIdempotencyRepository::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(BusinessRecordAccessController::class, static fn (
            Container $container,
        ): BusinessRecordAccessController => new DoctrineBusinessRecordAccessController(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, MembershipDirectory::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(NumberSequenceAllocator::class, static fn (
            Container $container,
        ): NumberSequenceAllocator => new DoctrineBusinessNumberSequenceAllocator(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(DoctrinePostingPeriodRepository::class, static fn (
            Container $container,
        ): DoctrinePostingPeriodRepository => new DoctrinePostingPeriodRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->alias(PostingPeriodRepository::class, DoctrinePostingPeriodRepository::class);
        $container->alias(PostingPeriodCalendar::class, DoctrinePostingPeriodRepository::class);
        $container->share(PostingPeriodLock::class, static fn (
            Container $container,
        ): PostingPeriodLock => new PostingPeriodLock(
            self::service($container, PostingPeriodRepository::class),
            self::service($container, RecordValueCodec::class),
        ), true);
        $container->share(PostingPeriodService::class, static fn (
            Container $container,
        ): PostingPeriodService => new PostingPeriodService(
            self::service($container, PostingPeriodRepository::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(BusinessRecordRelationshipCoordinator::class, static fn (
            Container $container,
        ): BusinessRecordRelationshipCoordinator => new BusinessRecordRelationshipCoordinator(
            self::service($container, BusinessRecordReadRepository::class),
            self::service($container, BusinessRecordMutationFence::class),
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, RecordValueCodec::class),
            self::service($container, RecordRuleValidator::class),
            self::service($container, DocumentCommitTimingRecorder::class),
        ), true);
        $container->share(BusinessRecordService::class, static fn (
            Container $container,
        ): BusinessRecordService => new BusinessRecordService(
            self::service($container, BusinessRecordWriteRepository::class),
            self::service($container, BusinessRecordReadRepository::class),
            self::service($container, BusinessRecordRevisionRepository::class),
            self::service($container, BusinessRecordIdempotencyRepository::class),
            self::service($container, BusinessRecordMutationFence::class),
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, NumberSequenceAllocator::class),
            self::service($container, RecordValueCodec::class),
            self::service($container, RecordRuleValidator::class),
            self::service($container, BusinessRecordAccessController::class),
            self::service($container, BusinessRecordRelationshipCoordinator::class),
            self::service($container, ApprovalService::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, BusinessRecordMutationPublication::class),
            self::service($container, RecordFingerprint::class),
            self::service($container, ClockInterface::class),
            self::service($container, PostingPeriodLock::class),
            self::service($container, PostingPeriodCalendar::class),
            $configuration->idempotencyReplay,
            commitTimings: self::service($container, DocumentCommitTimingRecorder::class),
        ), true);
        $container->share(
            BusinessDefinitionValidator::class,
            new BusinessDefinitionValidator($contributionRegistries->fieldTypes()),
            true,
        );
        $container->share(BusinessDefinitionService::class, static fn (
            Container $container,
        ): BusinessDefinitionService => new BusinessDefinitionService(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessDefinitionValidator::class),
            self::service($container, BusinessDefinitionCompatibilityAnalyzer::class),
            self::service($container, BusinessDefinitionContractAdmission::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            self::service($container, AuditRecorder::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            self::service($container, PublishedDefinitionSchemaObserver::class),
        ), true);
        $container->share(
            AdministratorNavigationRegistry::class,
            $contributionRegistries->navigation(),
            true,
        );
        $container->share(AdministratorViewRegistry::class, $contributionRegistries->views(), true);
        $container->share(PortalNavigationRegistry::class, $contributionRegistries->portalNavigation(), true);
        $container->share(PortalTemplateRegistry::class, $contributionRegistries->portalTemplates(), true);
        $container->share(ThemeActivationGuard::class, static fn (
            Container $container,
        ): ThemeActivationGuard => new DoctrineThemeActivationGuard(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, PasswordHasher::class),
            self::service($container, AuthenticationRateLimiter::class),
        ), true);
        $container->share(DoctrineThemeMutationAuthorizer::class, static fn (
            Container $container,
        ): DoctrineThemeMutationAuthorizer => new DoctrineThemeMutationAuthorizer(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->alias(ThemeMutationAuthorizer::class, DoctrineThemeMutationAuthorizer::class);
        $container->share(ThemePackageValidator::class, static fn (
            Container $container,
        ): ThemePackageValidator => new TwigThemePackageValidator(
            $root . '/templates',
            self::service($container, TranslationTwigExtension::class),
        ), true);
        $container->share(ExtensionRegistryFenceAllocator::class, static fn (
            Container $container,
        ): ExtensionRegistryFenceAllocator => new ExtensionRegistryFenceAllocator(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(ExtensionManager::class, static fn (Container $container): ExtensionManager =>
            new RedisLockedExtensionManager(
                new DoctrineExtensionManager(
                    self::service($container, Connection::class),
                    self::service($container, TableNames::class),
                    $extensionRoot,
                    $publicAssetRoot,
                    self::service($container, PackageInspector::class),
                    self::service($container, ArchiveContentReader::class),
                    self::service($container, PackageEvidenceInspector::class),
                    self::service($container, PackageAdmissionPolicy::class),
                    self::service($container, ExtensionMigrationRunner::class),
                    self::service($container, ExtensionRuntimeMapCompiler::class),
                    self::service($container, TransactionManager::class),
                    self::service($container, AuditRecorder::class),
                    self::service($container, ClockInterface::class),
                    self::service($container, EventManagerInterface::class),
                    self::service($container, ThemeActivationGuard::class),
                    self::service($container, ThemePackageValidator::class),
                    self::service($container, ThemeMutationAuthorizer::class),
                    self::service($container, TrustStore::class),
                    self::service($container, AuthorizationGateway::class),
                    self::service($container, ResourceSiteOwnershipWriter::class),
                    self::service($container, PackageDefinitionSynchronizer::class),
                    self::service($container, ExtensionActivationAdmission::class),
                ),
                self::service($container, RedisRuntime::class),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ExtensionRegistryFenceAllocator::class),
                self::service($container, TrustStore::class),
                self::service($container, ExtensionRuntimeWithdrawal::class),
                self::service($container, ExtensionExecutionGate::class),
            ), true);
        $container->alias(ExtensionInstallReconciler::class, ExtensionManager::class);
        $compiler = self::service($container, ExtensionRuntimeMapCompiler::class);
        $materialization = $compiler->inspectLocal();
        $container->share(RuntimeMaterializationState::class, $materialization, true);
        $execution = new CurrentExtensionExecutionGate($compiler, $materialization);
        $container->share(ExtensionExecutionGate::class, $execution, true);
        $active = $loadRuntime
            && $materialization->trusted
            && $materialization->publication !== null
            ? (new ExtensionRuntimeLoader(
                $materialization->publication,
                $extensionRoot,
                $keyRing,
                self::service($container, TrustStore::class),
                $execution,
            ))->load([
                CanonicalEncoder::class => self::service($container, CanonicalEncoder::class),
                BusinessRecordReader::class => new PolicyBusinessRecordReader(
                    self::service($container, BusinessRecordService::class),
                ),
                BusinessRecordService::class => self::service($container, BusinessRecordService::class),
                ContentService::class => self::service($container, ContentService::class),
                NavigationService::class => self::service($container, NavigationService::class),
                SiteSettings::class => self::service($container, SiteSettings::class),
            ], $contributionRegistries)
            : new ActiveExtensionSet($contributionRegistries, self::service($container, TrustStore::class));
        $container->share(ActiveExtensionSet::class, $active, true);
        $runtimeWithdrawal->bind($active);
        $container->share(QueueRuntimePolicyCatalog::class, new ContributedQueueRuntimePolicyCatalog(
            $contributionRegistries,
            $materialization,
        ), true);
        $container->share(QueueRuntimeOperations::class, static fn (
            Container $container,
        ): QueueRuntimeOperations => new DoctrineQueueRuntimeOperations(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, QueueRuntimePolicyCatalog::class),
        ), true);
        $contributionRegistries->validateIntegrationContributions();
        $eventSchemas = self::contributionDefinitions(
            $contributionRegistries->eventSchemas()->definitions(),
            EventSchemaDefinition::class,
        );
        $eventConsumers = self::contributionDefinitions(
            $contributionRegistries->eventConsumers()->definitions(),
            EventConsumerDefinition::class,
        );
        $jobs = self::contributionDefinitions(
            $contributionRegistries->jobs()->definitions(),
            JobContributionDefinition::class,
        );
        $eventContracts->replace($eventSchemas, $eventConsumers);
        self::service($container, JobExecutionScope::class)->replace($jobs);
        (new ContributedJobFormCompiler())->compile(
            $jobs,
            self::service($container, AutomationJobFormRegistry::class),
        );
        $container->share(ScheduleRuntimeSynchronizer::class, static fn (
            Container $container,
        ): ScheduleRuntimeSynchronizer => new ContributedScheduleSynchronizer(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            self::service($container, ExtensionContributionRegistrySet::class),
            self::service($container, RuntimeMaterializationState::class),
            queuePolicies: self::service($container, QueueRuntimePolicyCatalog::class),
        ), true);

        $container->share(TrustedRuntimeGenerationGuard::class, new ExtensionRuntimeGenerationGuard(
            $compiler,
            $materialization,
        ), true);
        $container->share(ProjectionRuntime::class, static fn (Container $container): ProjectionRuntime =>
            new DoctrineProjectionRuntime(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                self::service($container, TrustedRuntimeGenerationGuard::class),
                self::service($container, RuntimeMaterializationState::class),
                self::service($container, ExtensionContributionRegistrySet::class)
                    ->projections()
                    ->executableEntries(),
            ), true);
        $container->share(IntegrationEventConsumerDispatcher::class, static fn (
            Container $container,
        ): IntegrationEventConsumerDispatcher => new IntegrationEventConsumerDispatcher(
            self::service($container, InboxStore::class),
            self::service($container, EventContractRegistry::class),
            self::service($container, RetryPolicy::class),
            self::service($container, TrustedRuntimeGenerationGuard::class),
            self::service($container, TransactionManager::class),
            self::service($container, LoggerInterface::class),
            self::service($container, QueueRuntimePolicyCatalog::class),
        ), true);
        $container->share(DurableOutboundAdapterDispatcher::class, static fn (
            Container $container,
        ): DurableOutboundAdapterDispatcher => new DurableOutboundAdapterDispatcher(
            self::service($container, InboxStore::class),
            self::service($container, EventContractRegistry::class),
            self::service($container, RetryPolicy::class),
            self::service($container, TrustedRuntimeGenerationGuard::class),
            self::service($container, LoggerInterface::class),
            self::service($container, QueueRuntimePolicyCatalog::class),
        ), true);
        $container->share(IntegrationEventFanout::class, static fn (
            Container $container,
        ): IntegrationEventFanout => new RuntimeIntegrationEventTransport(
            self::service($container, ExtensionContributionRegistrySet::class),
            self::service($container, IntegrationEventConsumerDispatcher::class),
            self::service($container, DurableOutboundAdapterDispatcher::class),
            self::service($container, ProjectionRuntime::class),
            SystemPrincipal::issue($kernelProof, SystemIdentity::Worker),
            self::service($container, RuntimeMaterializationState::class),
        ), true);
        $container->share(OutboxDispatcher::class, static fn (Container $container): OutboxDispatcher =>
            new OutboxDispatcher(
                self::service($container, OutboxStore::class),
                self::service($container, EventContractRegistry::class),
                self::service($container, IntegrationEventFanout::class),
                self::service($container, RetryPolicy::class),
                self::service($container, TrustedRuntimeGenerationGuard::class),
                self::service($container, LoggerInterface::class),
            ), true);
        $container->share(ProcessWorkDispatcher::class, static fn (
            Container $container,
        ): ProcessWorkDispatcher => new ProcessWorkDispatcher(
            self::service($container, ProcessManagerStore::class),
            [new JobQueueProcessWorkHandler(
                self::service($container, JobQueue::class),
                self::service($container, ClockInterface::class),
            )],
            SystemPrincipal::issue($kernelProof, SystemIdentity::Worker),
            self::service($container, RetryPolicy::class),
            self::service($container, TrustedRuntimeGenerationGuard::class),
            self::service($container, TransactionManager::class),
            self::service($container, LoggerInterface::class),
        ), true);
        // Core owns the money conversion contract and ships no rate of any kind, so the catalog reads
        // the contribution registries and stays empty until a package that owns rates is installed.
        $container->share(MoneyConverter::class, new MoneyConverter(), true);
        $container->share(MoneyRateProviderCatalog::class, static fn (
            Container $container,
        ): MoneyRateProviderCatalog => new RuntimeMoneyRateProviderCatalog(
            self::service($container, ExtensionContributionRegistrySet::class),
            self::service($container, ExtensionExecutionGate::class),
        ), true);
        $container->share(MoneyConversionPipeline::class, static fn (
            Container $container,
        ): MoneyConversionPipeline => new MoneyConversionPipeline(
            self::service($container, MoneyConverter::class),
            self::service($container, MoneyRateProviderCatalog::class),
        ), true);
        // The unit-of-measure contract is wired the same way and for the same reason: core owns the
        // conversion and ships no table, so the catalog is empty until a package that owns one arrives.
        $container->share(QuantityConverter::class, new QuantityConverter(), true);
        $container->share(UnitConversionProviderCatalog::class, static fn (
            Container $container,
        ): UnitConversionProviderCatalog => new RuntimeUnitConversionProviderCatalog(
            self::service($container, ExtensionContributionRegistrySet::class),
            self::service($container, ExtensionExecutionGate::class),
        ), true);
        $container->share(UnitConversionPipeline::class, static fn (
            Container $container,
        ): UnitConversionPipeline => new UnitConversionPipeline(
            self::service($container, QuantityConverter::class),
            self::service($container, UnitConversionProviderCatalog::class),
        ), true);
    }

    /**
     * Register the delivery-neutral generated-business catalog, projection and contract services.
     *
     * Every browser, REST, console and MCP adapter resolves these same shared objects. The composition
     * root supplies only the trusted installed-definition resolver, canonical policy controller and
     * runtime publication state; adapters therefore cannot create an alternate metadata or disclosure
     * path. Core field presenters are registered eagerly so a missing built-in context fails at boot.
     *
     * @param   Container  $container    Container being composed.
     * @param   string     $root         Absolute repository root containing the checked-in core contract.
     * @param   object     $kernelProof  Private provenance for export queue producer contexts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerBusinessSurfaces(Container $container, string $root, object $kernelProof): void
    {
        $reportDefinitions = array_values(array_filter(
            self::service($container, ExtensionContributionRegistrySet::class)->reports()->definitions(),
            static fn (object $definition): bool => $definition instanceof ReportDefinition,
        ));
        $container->share(ReportDefinitionRegistry::class, new ReportDefinitionRegistry(
            $reportDefinitions,
            self::service($container, ExtensionExecutionGate::class),
        ), true);
        $container->share(ReportApiPresenter::class, new ReportApiPresenter(), true);
        $container->share(ReportScopeResolver::class, static fn (
            Container $container,
        ): ReportScopeResolver => new BusinessRecordReportScopeResolver(
            self::service($container, BusinessRecordDefinitionResolver::class),
        ), true);
        $container->share(RecordExportReportProvider::class, static fn (
            Container $container,
        ): RecordExportReportProvider => new RecordExportReportProvider(
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, DoctrinePersistedFieldTypeDefinitionResolver::class),
        ), true);
        $container->share(ReportService::class, static fn (Container $container): ReportService =>
            new ReportService(
                self::service($container, ReportDefinitionRegistry::class),
                new BusinessRecordServiceReportReader(self::service($container, BusinessRecordService::class)),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ReportScopeResolver::class),
                recordExports: self::service($container, RecordExportReportProvider::class),
            ), true);
        $container->share(ExportArtifactRepository::class, static fn (
            Container $container,
        ): ExportArtifactRepository => new DoctrineExportArtifactRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
        ), true);
        $container->share(ExportArtifactStorage::class, static fn (): ExportArtifactStorage =>
            new FilesystemExportArtifactStorage(
                $root . '/storage/private/report-exports/objects',
            ), true);
        $container->share(ExportPolicySnapshotProvider::class, static fn (
            Container $container,
        ): ExportPolicySnapshotProvider => new BusinessRecordExportPolicySnapshotProvider(
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, BusinessRecordAccessController::class),
        ), true);
        $container->share(ExportQueueProducerContextProvider::class, new SystemExportQueueProducerContextProvider(
            SystemPrincipal::issue($kernelProof, SystemIdentity::Worker),
        ), true);
        $container->share(ExportJobDispatcher::class, static fn (Container $container): ExportJobDispatcher =>
            new JobQueueExportJobDispatcher(
                self::service($container, JobQueue::class),
                self::service($container, ExportQueueProducerContextProvider::class),
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(ExportExecutionContextResolver::class, static fn (
            Container $container,
        ): ExportExecutionContextResolver => new LiveExportExecutionContextResolver(
            self::service($container, PortalPrincipalLoader::class),
            self::service($container, MembershipDirectory::class),
        ), true);
        $container->share(ExportService::class, static fn (Container $container): ExportService => new ExportService(
            self::service($container, ReportDefinitionRegistry::class),
            self::service($container, ReportScopeResolver::class),
            self::service($container, ExportArtifactRepository::class),
            self::service($container, ExportArtifactStorage::class),
            self::service($container, ExportJobDispatcher::class),
            self::service($container, ExportPolicySnapshotProvider::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, RecordExportReportProvider::class),
        ), true);
        $container->share(ReportCsvEncoder::class, new ReportCsvEncoder(), true);
        $container->share(ExportGenerationService::class, static fn (
            Container $container,
        ): ExportGenerationService => new ExportGenerationService(
            self::service($container, ExportArtifactRepository::class),
            self::service($container, ExportExecutionContextResolver::class),
            self::service($container, ExportService::class),
            self::service($container, ReportService::class),
            self::service($container, ReportCsvEncoder::class),
            self::service($container, ExportArtifactStorage::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(GenerateReportExportHandler::class, static fn (
            Container $container,
        ): GenerateReportExportHandler => new GenerateReportExportHandler(
            self::service($container, ExportGenerationService::class),
        ), true);

        $container->share(
            FieldPresentationRegistry::class,
            self::service($container, ExtensionContributionRegistrySet::class)->fieldPresentations(),
            true,
        );
        $container->share(FieldModelPresenter::class, static fn (
            Container $container,
        ): FieldModelPresenter => new RegistryFieldModelPresenter(
            self::service($container, FieldPresentationRegistry::class),
            self::service($container, ExtensionExecutionGate::class),
        ), true);
        $container->share(BusinessRecordQueryFactory::class, new BusinessRecordQueryFactory(), true);
        $container->share(BusinessRecordProjector::class, new BusinessRecordProjector(), true);
        $container->share(BusinessFormInputMapper::class, new BusinessFormInputMapper(), true);
        $container->share(BusinessCustomViewPresenter::class, static fn (
            Container $container,
        ): BusinessCustomViewPresenter => new BusinessCustomViewPresenter(
            self::service($container, Translator::class),
        ), true);
        $container->share(BusinessDocumentPresenter::class, new BusinessDocumentPresenter(), true);
        $container->share(GeneratedBusinessActionStepUp::class, static fn (
            Container $container,
        ): GeneratedBusinessActionStepUp => new GeneratedBusinessActionStepUp(
            self::service($container, AuthorizationStepUpProofAdapter::class),
            self::service($container, TransactionManager::class),
        ), true);
        $container->share(CustomBusinessSurfaceDispatcher::class, static fn (
            Container $container,
        ): CustomBusinessSurfaceDispatcher => new CustomBusinessSurfaceDispatcher(
            self::service($container, ExtensionContributionRegistrySet::class)->customBusinessViewHandlers(),
            self::service($container, ExtensionContributionRegistrySet::class)->customBusinessActionHandlers(),
            self::service($container, AuthorizationGateway::class),
            self::service($container, ExtensionExecutionGate::class),
        ), true);
        $container->share(CustomBusinessActionExecutor::class, static fn (
            Container $container,
        ): CustomBusinessActionExecutor => new CustomBusinessActionExecutor(
            self::service($container, CustomBusinessSurfaceDispatcher::class),
            self::service($container, BusinessRecordService::class),
            self::service($container, BusinessRecordIdempotencyRepository::class),
            self::service($container, BusinessRecordMutationFence::class),
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, BusinessRecordAccessController::class),
            self::service($container, RecordFingerprint::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            self::service($container, RuntimeMaterializationState::class),
        ), true);
        $container->share(BusinessSurfaceCatalog::class, static fn (
            Container $container,
        ): BusinessSurfaceCatalog => new BusinessSurfaceCatalog(
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, BusinessRecordAccessController::class),
            self::service($container, FieldTypeRegistry::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, RuntimeMaterializationState::class),
            self::service($container, CustomBusinessSurfaceDispatcher::class),
            self::service($container, ActiveLocale::class),
        ), true);
        $container->share(BusinessApprovalSurfaceService::class, static fn (
            Container $container,
        ): BusinessApprovalSurfaceService => new BusinessApprovalSurfaceService(
            self::service($container, ApprovalQueryService::class),
            self::service($container, BusinessSurfaceCatalog::class),
        ), true);
        $container->share(BusinessOperationStatusRepository::class, static fn (
            Container $container,
        ): BusinessOperationStatusRepository => new DoctrineBusinessOperationStatusRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, BusinessRecordIdempotencyRepository::class),
        ), true);
        $container->share(BusinessOperationStatusService::class, static fn (
            Container $container,
        ): BusinessOperationStatusService => new BusinessOperationStatusService(
            self::service($container, BusinessOperationStatusRepository::class),
            self::service($container, TransactionManager::class),
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, BusinessRecordAccessController::class),
            self::service($container, RecordFingerprint::class),
            self::service($container, BusinessSurfaceCatalog::class),
            self::service($container, BusinessRecordProjector::class),
            self::service($container, CustomBusinessSurfaceDispatcher::class),
            self::service($container, RuntimeMaterializationState::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(BusinessSurfaceService::class, static fn (
            Container $container,
        ): BusinessSurfaceService => new BusinessSurfaceService(
            self::service($container, BusinessSurfaceCatalog::class),
            self::service($container, BusinessRecordService::class),
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, FieldTypeRegistry::class),
            self::service($container, BusinessRecordQueryFactory::class),
            self::service($container, BusinessRecordProjector::class),
            self::service($container, CustomBusinessSurfaceDispatcher::class),
            self::service($container, CustomBusinessActionExecutor::class),
            self::service($container, FieldModelPresenter::class),
            self::service($container, MediaService::class),
            self::service($container, TransactionManager::class),
            self::service($container, ActiveLocale::class),
        ), true);
        $container->share(BusinessMutationPlanService::class, static fn (
            Container $container,
        ): BusinessMutationPlanService => new BusinessMutationPlanService(
            self::service($container, BusinessSurfaceCatalog::class),
            self::service($container, BusinessRecordService::class),
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, BusinessRecordAccessController::class),
            self::service($container, RecordFingerprint::class),
            self::service($container, MutationPlanCipher::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(GeneratedBusinessBrowserController::class, static fn (
            Container $container,
        ): GeneratedBusinessBrowserController => new GeneratedBusinessBrowserController(
            self::service($container, BusinessSurfaceService::class),
            self::service($container, BusinessFormInputMapper::class),
            self::service($container, BusinessOperationStatusService::class),
            self::service($container, BusinessCustomViewPresenter::class),
            self::service($container, BusinessDocumentPresenter::class),
            self::service($container, ReportService::class),
            self::service($container, RecordExportReportProvider::class),
            self::service($container, Translator::class),
        ), true);
        $container->share(OpenApiContractCompiler::class, new OpenApiContractCompiler(), true);
        $container->share(
            OpenApiContractCache::class,
            new FilesystemOpenApiContractCache($root . '/storage/cache/openapi'),
            true,
        );
        $container->share(OpenApiContractService::class, static function (
            Container $container,
        ) use ($root): OpenApiContractService {
            $json = file_get_contents($root . '/api/openapi/kumwe-v1.json');
            if ($json === false) {
                throw new RuntimeException('The checked-in core OpenAPI contract cannot be read.');
            }
            $contract = self::decodeOpenApiObject($json);

            return new OpenApiContractService(
                $contract,
                self::service($container, BusinessSurfaceCatalog::class),
                self::service($container, OpenApiContractCompiler::class),
                self::service($container, OpenApiContractCache::class),
                self::service($container, LoggerInterface::class),
            );
        }, true);
    }

    /**
     * Register every PSR-15 middleware the pipeline and the individual routes select from.
     *
     * Registration order carries no meaning here; `configureApplication()` decides the pipeline
     * order. The idempotency, if-match and CSRF middleware are registered even though only specific
     * routes name them, because a route references middleware by service name and Mezzio resolves it
     * from this container at dispatch time.
     *
     * @param   Container                 $container      Container being composed.
     * @param   ApplicationConfiguration  $configuration  Boot configuration for hosts, proxies and body limits.
     * @param   bool                      $portalEnabled  Whether to register portal boundary middleware.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerMiddleware(
        Container $container,
        ApplicationConfiguration $configuration,
        bool $portalEnabled,
    ): void {
        $container->share(RequestIdMiddleware::class, static fn (Container $container): RequestIdMiddleware =>
            new RequestIdMiddleware(self::service($container, CorrelationContext::class)), true);
        $container->share(MetricsMiddleware::class, static fn (Container $container): MetricsMiddleware =>
            new MetricsMiddleware(self::service($container, MetricRecorder::class)), true);
        $container->share(ProblemDetailsMiddleware::class, static function (
            Container $container,
        ) use ($configuration): ProblemDetailsMiddleware {
            return new ProblemDetailsMiddleware(
                self::service($container, LoggerInterface::class),
                $configuration->debug,
            );
        }, true);
        $container->share(TrustedProxyMiddleware::class, new TrustedProxyMiddleware(
            new TrustedProxyMatcher($configuration->trustedProxies),
        ), true);
        $container->share(TrustedHostMiddleware::class, new TrustedHostMiddleware(
            new TrustedHostMatcher($configuration->trustedHosts),
        ), true);
        $container->share(BodyLimitMiddleware::class, new BodyLimitMiddleware($configuration->maxBodyBytes), true);
        $container->share(ProblemDetailsResponseFactory::class, new ProblemDetailsResponseFactory(), true);
        $container->share(ExtensionRuntimeGenerationMiddleware::class, static fn (
            Container $container,
        ): ExtensionRuntimeGenerationMiddleware => new ExtensionRuntimeGenerationMiddleware(
            self::service($container, ExtensionExecutionGate::class),
            self::service($container, RuntimeMaterializationState::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(RequireIdempotencyKeyMiddleware::class, static function (
            Container $container,
        ): RequireIdempotencyKeyMiddleware {
            return new RequireIdempotencyKeyMiddleware(
                self::service($container, ProblemDetailsResponseFactory::class),
            );
        }, true);
        $container->share(IdempotencyLedger::class, static fn (
            Container $container,
        ): IdempotencyLedger => new DoctrineIdempotencyLedger(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(SecretOnceIdempotencyLedger::class, static fn (
            Container $container,
        ): SecretOnceIdempotencyLedger => new DoctrineSecretOnceIdempotencyLedger(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(PersistentIdempotencyMiddleware::class, static fn (
            Container $container,
        ): PersistentIdempotencyMiddleware => new PersistentIdempotencyMiddleware(
            self::service($container, IdempotencyLedger::class),
            self::service($container, ClockInterface::class),
            self::service($container, ProblemDetailsResponseFactory::class),
            self::service($container, TransactionManager::class),
            new HttpMutationPreauthorizer(
                self::service($container, AuthorizationGateway::class),
                self::service($container, ContentService::class),
                self::service($container, AccessControlRepository::class),
                self::service($container, TokenDelegationPreauthorizer::class),
                self::service($container, TokenRotationPreauthorizer::class),
                self::service($container, ContentModelRepository::class),
            ),
        ), true);
        $container->share(SecretOnceIdempotencyMiddleware::class, static fn (
            Container $container,
        ): SecretOnceIdempotencyMiddleware => new SecretOnceIdempotencyMiddleware(
            self::service($container, SecretOnceIdempotencyLedger::class),
            self::service($container, ProblemDetailsResponseFactory::class),
            new HttpMutationPreauthorizer(
                self::service($container, AuthorizationGateway::class),
                self::service($container, ContentService::class),
                self::service($container, AccessControlRepository::class),
                self::service($container, TokenDelegationPreauthorizer::class),
                self::service($container, TokenRotationPreauthorizer::class),
                self::service($container, ContentModelRepository::class),
            ),
            self::service($container, TransactionManager::class),
        ), true);
        $container->share(RequireIfMatchMiddleware::class, static function (
            Container $container,
        ): RequireIfMatchMiddleware {
            return new RequireIfMatchMiddleware(
                self::service($container, ProblemDetailsResponseFactory::class),
            );
        }, true);
        $container->share(AdministratorSessionMiddleware::class, static fn (
            Container $container,
        ): AdministratorSessionMiddleware => new AdministratorSessionMiddleware(
            self::service($container, AdministratorSessionStore::class),
            self::service($container, AuthorizationGateway::class),
            SiteContext::fromString($configuration->publicSite),
        ), true);
        $container->share(AdministratorAuthorizationMiddleware::class, static fn (
            Container $container,
        ): AdministratorAuthorizationMiddleware => new AdministratorAuthorizationMiddleware(
            self::service($container, AdministratorRenderer::class),
        ), true);
        $container->share(AdministratorCsrfMiddleware::class, static fn (
            Container $container,
        ): AdministratorCsrfMiddleware => new AdministratorCsrfMiddleware(
            self::service($container, Translator::class),
            self::service($container, ActiveLocale::class),
        ), true);
        if ($portalEnabled) {
            $container->share(PortalSessionMiddleware::class, static fn (
                Container $container,
            ): PortalSessionMiddleware => new PortalSessionMiddleware(
                self::service($container, PortalSessionStore::class),
                self::service($container, PortalExecutionContextFactory::class),
                self::service($container, AuthorizationGateway::class),
            ), true);
            $container->share(PortalAuthorizationMiddleware::class, static fn (
                Container $container,
            ): PortalAuthorizationMiddleware => new PortalAuthorizationMiddleware(
                self::service($container, AuthorizationGateway::class),
            ), true);
            $container->share(PortalCsrfMiddleware::class, static fn (
                Container $container,
            ): PortalCsrfMiddleware => new PortalCsrfMiddleware(
                self::service($container, Translator::class),
                self::service($container, ActiveLocale::class),
            ), true);
        }
        $container->share(BearerAuthenticationMiddleware::class, static function (
            Container $container,
        ): BearerAuthenticationMiddleware {
            return new BearerAuthenticationMiddleware(self::service($container, AccessTokenVerifier::class));
        }, true);
        $container->share(SecurityHeadersMiddleware::class, new SecurityHeadersMiddleware(
            $configuration->isProduction(),
            StudioBrowserAssetLocator::npmPackages($configuration->studioBrowserBaseUrl)->origin(),
        ), true);
        $container->share(RouteMiddleware::class, static fn (Container $container): RouteMiddleware =>
            new RouteMiddleware(self::service($container, RouterInterface::class)), true);
        $container->share(ImplicitHeadMiddleware::class, static fn (Container $container): ImplicitHeadMiddleware =>
            new ImplicitHeadMiddleware(
                self::service($container, RouterInterface::class),
                self::service($container, StreamFactoryInterface::class),
            ), true);
        $container->share(ImplicitOptionsMiddleware::class, static function (
            Container $container,
        ): ImplicitOptionsMiddleware {
            return new ImplicitOptionsMiddleware(
                self::service($container, ResponseFactoryInterface::class),
            );
        }, true);
        $container->share(MethodNotAllowedMiddleware::class, static function (
            Container $container,
        ): MethodNotAllowedMiddleware {
            return new MethodNotAllowedMiddleware(
                self::service($container, ResponseFactoryInterface::class),
            );
        }, true);
        $container->share(DispatchMiddleware::class, new DispatchMiddleware(), true);
    }

    /**
     * Register every request handler, presenter and responder the routes dispatch to.
     *
     * Administrator cookie security is derived here from the configured base URL scheme, so an
     * installation served over plain HTTP still issues a usable session cookie while an HTTPS
     * deployment gets a secure one without a second configuration switch.
     *
     * @param   Container                 $container      Container being composed.
     * @param   ApplicationConfiguration  $configuration  Boot configuration for base URL, site and limits.
     * @param   string                    $root           Absolute path of the repository root.
     * @param   bool                      $portalEnabled  Whether to register ordinary-user portal handlers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerHandlers(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
        bool $portalEnabled,
    ): void {
        $container->share(ContentLayoutCatalog::class, static fn (
            Container $container,
        ): ContentLayoutCatalog => new ContentLayoutCatalog(
            self::service($container, ContentModelRepository::class),
            self::service($container, ApplicationConfiguration::class)->publicSite,
        ), true);
        $container->share(HomePageHandler::class, static fn (Container $container): HomePageHandler =>
            new HomePageHandler(
                self::service($container, PublicPageLocator::class),
                self::service($container, ContentPageRenderService::class),
                self::service($container, ContentPresenter::class),
                self::service($container, ContentLayoutCatalog::class),
                self::service($container, TranslationGroupPresenter::class),
                self::service($container, ActiveLocale::class),
                self::service($container, StudioPublishedContentRenderer::class),
                self::service($container, StudioPublishedEnhancementRuntime::class),
            ), true);
        $container->share(StudioPublishedEnhancementRuntime::class, static fn (
            Container $container,
        ): StudioPublishedEnhancementRuntime => new StudioPublishedEnhancementRuntime(
            self::service($container, StudioBrowserAssetLocator::class),
        ), true);
        $container->share(LivenessHandler::class, new LivenessHandler(), true);
        $container->share(MetricsHandler::class, static fn (Container $container): MetricsHandler =>
            new MetricsHandler(
                self::service($container, MetricsAccessPolicy::class),
                self::service($container, MetricCatalog::class),
                self::service($container, MetricRecorder::class),
                self::service($container, RuntimeMetricCollector::class),
                self::service($container, PrometheusExposition::class),
            ), true);
        $container->share(ApiIndexHandler::class, new ApiIndexHandler(), true);
        $container->share(NotFoundHandler::class, new NotFoundHandler(), true);
        $container->share(ReadinessHandler::class, static fn (Container $container): ReadinessHandler =>
            new ReadinessHandler(new LocalRuntimeReadinessProbe(
                self::service($container, ExtensionRuntimeMapCompiler::class),
            )), true);
        $container->share(RobotsHandler::class, static fn (Container $container): RobotsHandler =>
            new RobotsHandler(self::service($container, SiteSettings::class)), true);
        $container->share(SafePlanFactory::class, static fn (Container $container): SafePlanFactory =>
            new SafePlanFactory(self::service($container, ClockInterface::class)), true);
        $container->share(PlanPreviewHandler::class, static fn (Container $container): PlanPreviewHandler =>
            new PlanPreviewHandler(
                self::service($container, SafePlanFactory::class),
                self::service($container, ProblemDetailsResponseFactory::class),
            ), true);
        $container->share(ContentApiResponder::class, static fn (Container $container): ContentApiResponder =>
            new ContentApiResponder(self::service($container, ProblemDetailsResponseFactory::class)), true);
        $container->share(ContentCollectionHandler::class, static fn (
            Container $container,
        ): ContentCollectionHandler => new ContentCollectionHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(ContentItemHandler::class, static fn (
            Container $container,
        ): ContentItemHandler => new ContentItemHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(ContentModelApiHandler::class, static fn (
            Container $container,
        ): ContentModelApiHandler => new ContentModelApiHandler(
            self::service($container, ContentModelService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(ContentTransitionHandler::class, static fn (
            Container $container,
        ): ContentTransitionHandler => new ContentTransitionHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(ContentRestoreHandler::class, static fn (
            Container $container,
        ): ContentRestoreHandler => new ContentRestoreHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(PublishedContentHandler::class, static fn (
            Container $container,
        ): PublishedContentHandler => new PublishedContentHandler(
            self::service($container, PublicPageLocator::class),
            self::service($container, ContentPageRenderService::class),
            self::service($container, ContentPresenter::class),
            self::service($container, ContentLayoutCatalog::class),
            self::service($container, TranslationGroupPresenter::class),
            self::service($container, ActiveLocale::class),
            self::service($container, StudioPublishedContentRenderer::class),
            self::service($container, StudioPublishedEnhancementRuntime::class),
        ), true);
        $container->share(StudioPublishedStylesheetHandler::class, static fn (
            Container $container,
        ): StudioPublishedStylesheetHandler => new StudioPublishedStylesheetHandler(
            self::service($container, PublicPageLocator::class),
            self::service($container, TranslationGroupPresenter::class),
            self::service($container, StudioPublishedContentRenderer::class),
            SiteContext::fromString($configuration->publicSite),
        ), true);
        $container->share(ExtensionAssetHandler::class, static fn (
            Container $container,
        ): ExtensionAssetHandler => new ExtensionAssetHandler(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
            self::service($container, StreamFactoryInterface::class),
            $root . '/public/assets/extensions',
        ), true);
        $container->share(MediaAssetHandler::class, static fn (
            Container $container,
        ): MediaAssetHandler => new MediaAssetHandler(
            self::service($container, MediaStorage::class),
            self::service($container, StreamFactoryInterface::class),
            SiteContext::fromString($configuration->publicSite),
        ), true);
        $configuration = self::service($container, ApplicationConfiguration::class);
        $secureCookie = parse_url($configuration->baseUrl, PHP_URL_SCHEME) === 'https';
        $container->share(AdministratorLoginHandler::class, static fn (
            Container $container,
        ): AdministratorLoginHandler => new AdministratorLoginHandler(
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, AdministratorSessionStore::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, Translator::class),
            $secureCookie,
            $configuration->administratorSessionSeconds,
            SiteContext::fromString($configuration->publicSite),
        ), true);
        $container->share(AdministratorLogoutHandler::class, static fn (
            Container $container,
        ): AdministratorLogoutHandler => new AdministratorLogoutHandler(
            self::service($container, AdministratorSessionStore::class),
            $secureCookie,
        ), true);
        if ($portalEnabled) {
            $container->share(PortalLoginHandler::class, static fn (
                Container $container,
            ): PortalLoginHandler => new PortalLoginHandler(
                self::service($container, PortalAuthenticator::class),
                self::service($container, PortalContextResolver::class),
                self::service($container, PortalSessionStore::class),
                self::service($container, PortalRenderer::class),
                self::service($container, Translator::class),
                $secureCookie,
                $configuration->administratorSessionSeconds,
            ), true);
            $container->share(PortalLogoutHandler::class, static fn (
                Container $container,
            ): PortalLogoutHandler => new PortalLogoutHandler(
                self::service($container, PortalSessionStore::class),
                $secureCookie,
            ), true);
            $container->share(PortalHomeHandler::class, static fn (
                Container $container,
            ): PortalHomeHandler => new PortalHomeHandler(
                self::service($container, PortalRenderer::class),
                self::service($container, DashboardComposer::class),
                self::service($container, DashboardPreferenceService::class),
                self::service($container, DashboardPreferenceFormPresenter::class),
                self::service($container, DashboardPreferenceQueryDecoder::class),
            ), true);
            $container->share(PortalDashboardPreferencesHandler::class, static fn (
                Container $container,
            ): PortalDashboardPreferencesHandler => new PortalDashboardPreferencesHandler(
                self::service($container, DashboardPreferenceService::class),
                self::service($container, DashboardPreferenceFormDecoder::class),
                self::service($container, DashboardPreferenceQueryDecoder::class),
                self::service($container, PortalRenderer::class),
            ), true);
            $container->share(PortalSecurityHandler::class, static fn (
                Container $container,
            ): PortalSecurityHandler => new PortalSecurityHandler(
                self::service($container, StepUpProvider::class),
                self::service($container, PortalRenderer::class),
                self::service($container, Translator::class),
                $secureCookie,
                $configuration->administratorSessionSeconds,
            ), true);
            $container->share(PortalApprovalHandler::class, static fn (
                Container $container,
            ): PortalApprovalHandler => new PortalApprovalHandler(
                self::service($container, BusinessApprovalSurfaceService::class),
                self::service($container, ApprovalService::class),
                self::service($container, StepUpProvider::class),
                self::service($container, AuthorizationStepUpProofAdapter::class),
                self::service($container, TransactionManager::class),
                self::service($container, PortalRenderer::class),
                self::service($container, Translator::class),
                $secureCookie,
                $configuration->administratorSessionSeconds,
            ), true);
            $container->share(PortalReportHandler::class, static fn (
                Container $container,
            ): PortalReportHandler => new PortalReportHandler(
                self::service($container, ReportService::class),
                self::service($container, ExportService::class),
                self::service($container, ReportApiPresenter::class),
                self::service($container, PortalRenderer::class),
                self::service($container, StreamFactoryInterface::class),
            ), true);
        }
        $container->share(AdministratorDashboardHandler::class, static fn (
            Container $container,
        ): AdministratorDashboardHandler => new AdministratorDashboardHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentModelService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, DashboardComposer::class),
            self::service($container, DashboardPreferenceService::class),
            self::service($container, DashboardPreferenceFormPresenter::class),
            self::service($container, DashboardPreferenceQueryDecoder::class),
        ), true);
        $container->share(AdministratorDashboardPreferencesHandler::class, static fn (
            Container $container,
        ): AdministratorDashboardPreferencesHandler => new AdministratorDashboardPreferencesHandler(
            self::service($container, DashboardPreferenceService::class),
            self::service($container, DashboardPreferenceFormDecoder::class),
            self::service($container, DashboardPreferenceQueryDecoder::class),
            self::service($container, AdministratorRenderer::class),
        ), true);
        $container->share(AdministratorStudioSessionHandler::class, static fn (
            Container $container,
        ): AdministratorStudioSessionHandler => new AdministratorStudioSessionHandler(
            self::service($container, StudioHostSessionAuthority::class),
            self::service($container, StudioPreviewTransportGuard::class),
        ), true);
        $container->share(AdministratorStudioHostHandler::class, static fn (
            Container $container,
        ): AdministratorStudioHostHandler => new AdministratorStudioHostHandler(
            self::service($container, StudioProducerHostFactory::class),
            $configuration->maxBodyBytes,
        ), true);
        $container->share(AdministratorStudioPreviewDocumentHandler::class, static fn (
            Container $container,
        ): AdministratorStudioPreviewDocumentHandler => new AdministratorStudioPreviewDocumentHandler(
            self::service($container, StudioHostSessionAuthority::class),
            self::service($container, StudioPreviewHostPort::class),
        ), true);
        $container->share(AdministratorStudioPreviewStylesheetHandler::class, static fn (
            Container $container,
        ): AdministratorStudioPreviewStylesheetHandler => new AdministratorStudioPreviewStylesheetHandler(
            self::service($container, StudioHostSessionAuthority::class),
            self::service($container, StudioPreviewHostPort::class),
        ), true);
        $container->share(AdministratorStudioMediaUploadHandler::class, static fn (
            Container $container,
        ): AdministratorStudioMediaUploadHandler => new AdministratorStudioMediaUploadHandler(
            self::service($container, StudioMediaOperations::class),
            self::service($container, StudioHostSessionAuthority::class),
        ), true);
        $container->share(AdministratorStudioCompositionHandler::class, static fn (
            Container $container,
        ): AdministratorStudioCompositionHandler => new AdministratorStudioCompositionHandler(
            self::service($container, StudioContentCompositionService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, ActiveLocale::class),
            self::service($container, SiteSettings::class),
            self::service($container, StudioCompositionContributionCatalog::class),
            self::service($container, StudioReleaseRecord::class),
        ), true);
        $container->share(AdministratorInterfaceStandardHandler::class, static fn (
            Container $container,
        ): AdministratorInterfaceStandardHandler => new AdministratorInterfaceStandardHandler(
            self::service($container, AdministratorRenderer::class),
        ), true);
        $container->share(AdministratorReportHandler::class, static fn (
            Container $container,
        ): AdministratorReportHandler => new AdministratorReportHandler(
            self::service($container, ReportService::class),
            self::service($container, ExportService::class),
            self::service($container, ReportApiPresenter::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, StreamFactoryInterface::class),
        ), true);
        $container->share(AdministratorContentListHandler::class, static fn (
            Container $container,
        ): AdministratorContentListHandler => new AdministratorContentListHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentModelService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, PublicPageLocator::class),
        ), true);
        $container->share(BusinessDefinitionsHandler::class, static fn (
            Container $container,
        ): BusinessDefinitionsHandler => new BusinessDefinitionsHandler(
            self::service($container, BusinessDefinitionService::class),
            new BusinessDefinitionFormMapper(),
            self::service($container, ExtensionContributionRegistrySet::class)->fieldTypes(),
            self::service($container, AdministratorRenderer::class),
        ), true);
        $container->share(
            BusinessDefinitionApiPresenter::class,
            static fn (): BusinessDefinitionApiPresenter => new BusinessDefinitionApiPresenter(),
            true,
        );
        $container->share(
            BusinessSchemaApiPresenter::class,
            static fn (): BusinessSchemaApiPresenter => new BusinessSchemaApiPresenter(),
            true,
        );
        $container->share(BusinessApiResponder::class, static fn (
            Container $container,
        ): BusinessApiResponder => new BusinessApiResponder(
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(BusinessRecordApiPresenter::class, static fn (
            Container $container,
        ): BusinessRecordApiPresenter => new BusinessRecordApiPresenter(
            self::service($container, BusinessRecordProjector::class),
        ), true);
        $container->share(BusinessRecordApiResponder::class, static fn (
            Container $container,
        ): BusinessRecordApiResponder => new BusinessRecordApiResponder(
            self::service($container, BusinessRecordApiPresenter::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(BusinessRecordApiHandler::class, static fn (
            Container $container,
        ): BusinessRecordApiHandler => new BusinessRecordApiHandler(
            self::service($container, BusinessRecordService::class),
            self::service($container, BusinessRecordQueryFactory::class),
            self::service($container, BusinessRecordApiResponder::class),
            self::service($container, BusinessSurfaceCatalog::class),
            self::service($container, BusinessSurfaceService::class),
        ), true);
        $container->share(ReportApiHandler::class, static fn (
            Container $container,
        ): ReportApiHandler => new ReportApiHandler(
            self::service($container, ReportService::class),
            self::service($container, ExportService::class),
            self::service($container, ReportApiPresenter::class),
            self::service($container, StreamFactoryInterface::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(BusinessOperationStatusApiHandler::class, static fn (
            Container $container,
        ): BusinessOperationStatusApiHandler => new BusinessOperationStatusApiHandler(
            self::service($container, BusinessOperationStatusService::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(
            BusinessApprovalApiPresenter::class,
            new BusinessApprovalApiPresenter(),
            true,
        );
        $container->share(BusinessApprovalApiHandler::class, static fn (
            Container $container,
        ): BusinessApprovalApiHandler => new BusinessApprovalApiHandler(
            self::service($container, BusinessApprovalSurfaceService::class),
            self::service($container, BusinessApprovalApiPresenter::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(BusinessDefinitionDiscoveryApiHandler::class, static fn (
            Container $container,
        ): BusinessDefinitionDiscoveryApiHandler => new BusinessDefinitionDiscoveryApiHandler(
            self::service($container, BusinessSurfaceCatalog::class),
            self::service($container, BusinessRecordApiResponder::class),
        ), true);
        $container->share(OpenApiHandler::class, static fn (Container $container): OpenApiHandler =>
            new OpenApiHandler(
                self::service($container, OpenApiContractService::class),
                self::service($container, ProblemDetailsResponseFactory::class),
            ), true);
        $container->share(AdministratorBusinessSurfaceHandler::class, static fn (
            Container $container,
        ): AdministratorBusinessSurfaceHandler => new AdministratorBusinessSurfaceHandler(
            self::service($container, GeneratedBusinessBrowserController::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, AdministratorStepUpProvider::class),
            self::service($container, GeneratedBusinessActionStepUp::class),
            self::service($container, Translator::class),
            $secureCookie,
            $configuration->administratorSessionSeconds,
        ), true);
        if ($portalEnabled) {
            $container->share(PortalBusinessSurfaceHandler::class, static fn (
                Container $container,
            ): PortalBusinessSurfaceHandler => new PortalBusinessSurfaceHandler(
                self::service($container, GeneratedBusinessBrowserController::class),
                self::service($container, PortalRenderer::class),
                self::service($container, StepUpProvider::class),
                self::service($container, GeneratedBusinessActionStepUp::class),
                self::service($container, Translator::class),
                $secureCookie,
                $configuration->administratorSessionSeconds,
            ), true);
        }
        $container->share(BusinessDefinitionApiHandler::class, static fn (
            Container $container,
        ): BusinessDefinitionApiHandler => new BusinessDefinitionApiHandler(
            self::service($container, BusinessDefinitionService::class),
            self::service($container, BusinessDefinitionApiPresenter::class),
            self::service($container, BusinessApiResponder::class),
        ), true);
        $container->share(BusinessSchemaApiHandler::class, static fn (
            Container $container,
        ): BusinessSchemaApiHandler => new BusinessSchemaApiHandler(
            self::service($container, BusinessSchemaService::class),
            self::service($container, BusinessSchemaApiPresenter::class),
            self::service($container, BusinessApiResponder::class),
            self::service($container, HighImpactCredentialGuard::class),
        ), true);
        $container->share(PostingPeriodApiHandler::class, static fn (
            Container $container,
        ): PostingPeriodApiHandler => new PostingPeriodApiHandler(
            self::service($container, PostingPeriodService::class),
            self::service($container, BusinessApiResponder::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(BusinessSchemaPlansHandler::class, static fn (
            Container $container,
        ): BusinessSchemaPlansHandler => new BusinessSchemaPlansHandler(
            self::service($container, BusinessSchemaService::class),
            self::service($container, BusinessSchemaEnvironment::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, Translator::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(CreateBusinessSchemaPlanHandler::class, static fn (
            Container $container,
        ): CreateBusinessSchemaPlanHandler => new CreateBusinessSchemaPlanHandler(
            self::service($container, BusinessSchemaService::class),
        ), true);
        $container->share(CreateBusinessSchemaPurgePlanHandler::class, static fn (
            Container $container,
        ): CreateBusinessSchemaPurgePlanHandler => new CreateBusinessSchemaPurgePlanHandler(
            self::service($container, BusinessSchemaService::class),
            self::service($container, HighImpactCredentialGuard::class),
        ), true);
        $container->share(ApproveBusinessSchemaPlanHandler::class, static fn (
            Container $container,
        ): ApproveBusinessSchemaPlanHandler => new ApproveBusinessSchemaPlanHandler(
            self::service($container, BusinessSchemaService::class),
            self::service($container, HighImpactCredentialGuard::class),
        ), true);
        $container->share(ExecuteBusinessSchemaPlanHandler::class, static fn (
            Container $container,
        ): ExecuteBusinessSchemaPlanHandler => new ExecuteBusinessSchemaPlanHandler(
            self::service($container, BusinessSchemaService::class),
        ), true);
        $container->share(RecoverBusinessSchemaPlanHandler::class, static fn (
            Container $container,
        ): RecoverBusinessSchemaPlanHandler => new RecoverBusinessSchemaPlanHandler(
            self::service($container, BusinessSchemaService::class),
        ), true);
        $container->share(RecordBusinessSchemaRecoveryEvidenceHandler::class, static fn (
            Container $container,
        ): RecordBusinessSchemaRecoveryEvidenceHandler => new RecordBusinessSchemaRecoveryEvidenceHandler(
            self::service($container, BusinessSchemaService::class),
            self::service($container, BusinessSchemaEnvironment::class),
            self::service($container, HighImpactCredentialGuard::class),
        ), true);
        $container->share(AdministratorContentEditorHandler::class, static fn (
            Container $container,
        ): AdministratorContentEditorHandler => new AdministratorContentEditorHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentModelService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, ContentStudioAuthoringTargetResolver::class),
            self::service($container, ContentStudioAuthoringLaunchResolver::class),
            self::service($container, ContentFormPresenter::class),
            self::service($container, MediaService::class),
            self::service($container, PublicPageLocator::class),
        ), true);
        $container->share(AdministratorMediaHandler::class, static fn (
            Container $container,
        ): AdministratorMediaHandler => new AdministratorMediaHandler(
            self::service($container, MediaService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, Translator::class),
            $root . '/storage/tmp',
        ), true);
        $container->share(AdministratorContentModelsHandler::class, static fn (
            Container $container,
        ): AdministratorContentModelsHandler => new AdministratorContentModelsHandler(
            self::service($container, ContentModelService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, ContentModelFormMapper::class),
            self::service($container, ContentModelFormPresenter::class),
        ), true);
        $container->share(AdministratorCreateContentHandler::class, static fn (
            Container $container,
        ): AdministratorCreateContentHandler => new AdministratorCreateContentHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentModelService::class),
            self::service($container, ContentFormDataMapper::class),
            self::service($container, AdministratorContentEditorHandler::class),
        ), true);
        $container->share(AdministratorUpdateContentHandler::class, static fn (
            Container $container,
        ): AdministratorUpdateContentHandler => new AdministratorUpdateContentHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentModelService::class),
            self::service($container, ContentFormDataMapper::class),
            self::service($container, AdministratorContentEditorHandler::class),
        ), true);
        $container->share(AdministratorTransitionContentHandler::class, static fn (
            Container $container,
        ): AdministratorTransitionContentHandler => new AdministratorTransitionContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(AdministratorTrashContentHandler::class, static fn (
            Container $container,
        ): AdministratorTrashContentHandler => new AdministratorTrashContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(AdministratorRestoreContentHandler::class, static fn (
            Container $container,
        ): AdministratorRestoreContentHandler => new AdministratorRestoreContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(AdministratorExtensionsHandler::class, static fn (
            Container $container,
        ): AdministratorExtensionsHandler => new AdministratorExtensionsHandler(
            self::service($container, ExtensionManager::class),
            self::service($container, TrustStore::class),
            self::service($container, AdministratorRenderer::class),
            dirname(__DIR__, 2) . '/storage/tmp',
            self::service($container, RevocationFeedSynchronizer::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(AdministratorExtensionActionHandler::class, static fn (
            Container $container,
        ): AdministratorExtensionActionHandler => new AdministratorExtensionActionHandler(
            self::service($container, ExtensionManager::class),
            self::service($container, TrustStore::class),
        ), true);
        $container->share(AdministratorSettingsHandler::class, static fn (
            Container $container,
        ): AdministratorSettingsHandler => new AdministratorSettingsHandler(
            self::service($container, SiteSettings::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, SitePresentationFormMapper::class),
            self::service($container, ContentService::class),
            self::service($container, MediaService::class),
            self::service($container, NavigationService::class),
        ), true);
        $container->share(AdministratorWordingHandler::class, static fn (
            Container $container,
        ): AdministratorWordingHandler => new AdministratorWordingHandler(
            self::service($container, MessageOverrideService::class),
            self::service($container, SupportedLocales::class),
            self::service($container, AdministratorRenderer::class),
        ), true);
        $container->share(AdministratorNavigationHandler::class, static fn (
            Container $container,
        ): AdministratorNavigationHandler => new AdministratorNavigationHandler(
            self::service($container, NavigationService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, ContentService::class),
            self::service($container, SiteSettings::class),
        ), true);
        $container->share(AdministratorAccessControlHandler::class, static fn (
            Container $container,
        ): AdministratorAccessControlHandler => new AdministratorAccessControlHandler(
            self::service($container, AccessControlService::class),
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, AdministratorSessionStore::class),
            self::service($container, MembershipDirectory::class),
            self::service($container, AdministratorStepUpProvider::class),
            self::service($container, AuthorizationStepUpProofAdapter::class),
            self::service($container, StepUpProofConsumer::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            $secureCookie,
            $configuration->administratorSessionSeconds,
        ), true);
        $container->share(AdministratorBusinessSecurityHandler::class, static fn (
            Container $container,
        ): AdministratorBusinessSecurityHandler => new AdministratorBusinessSecurityHandler(
            self::service($container, BusinessSecurityAdministrationService::class),
            self::service($container, ApprovalService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, Translator::class),
            self::service($container, AdministratorStepUpProvider::class),
            self::service($container, AuthorizationStepUpProofAdapter::class),
            self::service($container, TransactionManager::class),
            $secureCookie,
            $configuration->administratorSessionSeconds,
        ), true);
        $container->share(AdministratorAutomationHandler::class, static fn (
            Container $container,
        ): AdministratorAutomationHandler => new AdministratorAutomationHandler(
            self::service($container, AutomationManagementService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, AutomationJobFormRegistry::class),
        ), true);
        $container->share(NavigationApiResponder::class, static fn (
            Container $container,
        ): NavigationApiResponder => new NavigationApiResponder(
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(MenuCollectionHandler::class, static fn (Container $container): MenuCollectionHandler =>
            new MenuCollectionHandler(
                self::service($container, NavigationService::class),
                self::service($container, NavigationApiResponder::class),
            ), true);
        $container->share(MenuResourceHandler::class, static fn (Container $container): MenuResourceHandler =>
            new MenuResourceHandler(
                self::service($container, NavigationService::class),
                self::service($container, NavigationApiResponder::class),
            ), true);
        $container->share(MenuItemCollectionHandler::class, static fn (
            Container $container,
        ): MenuItemCollectionHandler => new MenuItemCollectionHandler(
            self::service($container, NavigationService::class),
            self::service($container, NavigationApiResponder::class),
        ), true);
        $container->share(MenuItemResourceHandler::class, static fn (
            Container $container,
        ): MenuItemResourceHandler => new MenuItemResourceHandler(
            self::service($container, NavigationService::class),
            self::service($container, NavigationApiResponder::class),
        ), true);
        $container->share(AccessControlApiHandler::class, static fn (
            Container $container,
        ): AccessControlApiHandler => new AccessControlApiHandler(
            self::service($container, AccessControlService::class),
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(SiteSettingsApiHandler::class, static fn (
            Container $container,
        ): SiteSettingsApiHandler => new SiteSettingsApiHandler(
            self::service($container, SiteSettings::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(ExtensionApiHandler::class, static fn (
            Container $container,
        ): ExtensionApiHandler => new ExtensionApiHandler(
            self::service($container, ExtensionManager::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(TrustStoreApiHandler::class, static fn (
            Container $container,
        ): TrustStoreApiHandler => new TrustStoreApiHandler(
            self::service($container, TrustStore::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(TrustLifecycleMiddleware::class, static fn (
            Container $container,
        ): TrustLifecycleMiddleware => new TrustLifecycleMiddleware(
            self::service($container, TrustStore::class),
        ), true);
        $container->share(AutomationApiHandler::class, static fn (
            Container $container,
        ): AutomationApiHandler => new AutomationApiHandler(
            self::service($container, AutomationManagementService::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(McpHttpHandler::class, static function (
            Container $container,
        ): McpHttpHandler {
            $configuration = self::service($container, ApplicationConfiguration::class);
            $host = parse_url($configuration->baseUrl, PHP_URL_HOST);

            if (!is_string($host) || $host === '') {
                throw new RuntimeException('The configured Kumwe base URL has no usable MCP host.');
            }

            return new McpHttpHandler(
                self::service($container, KumweMcpServerFactory::class),
                self::service($container, KumweMcpHandlers::class),
                self::service($container, ResponseFactoryInterface::class),
                self::service($container, StreamFactoryInterface::class),
                self::service($container, LoggerInterface::class),
                $configuration->maxBodyBytes,
                [$host],
            );
        }, true);
    }

    /**
     * Pipe the middleware and declare every route the application answers.
     *
     * Pipeline order is the security contract: request identity and problem details wrap everything,
     * the trusted proxy and host filters run before the body limit and the security headers, and
     * routing, session, authorization and bearer authentication all precede dispatch. Routes are
     * declared core first, then the routes contributed by active extensions, then the catch-all
     * published-content route, so an extension can add a path but never shadow a core one.
     *
     * Locale negotiation sits immediately after request identity, ahead of the error boundary, so a
     * problem document manufactured for a rejected request is rendered in the caller's language
     * rather than in whatever language the previous request left behind in a long-lived process.
     *
     * Metric recording sits between request identity and the error boundary, which is the only place
     * it counts every response including the ones the error boundary manufactured. The `/metrics`
     * route is declared unconditionally so the compiled route graph never depends on a deployment
     * flag; whether it answers is `MetricsAccessPolicy`'s decision, taken per request.
     *
     * @param   Application  $application    Mezzio application to pipe middleware into and route.
     * @param   Container    $container      Container the application resolves handlers from.
     * @param   bool         $portalEnabled  Whether to pipe and declare the ordinary-user portal boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function configureApplication(
        Application $application,
        Container $container,
        bool $portalEnabled,
    ): void {
        $application->pipe(RequestIdMiddleware::class);
        $application->pipe(LocaleNegotiationMiddleware::class);
        $application->pipe(MetricsMiddleware::class);
        $application->pipe(ProblemDetailsMiddleware::class);
        $application->pipe(TrustedProxyMiddleware::class);
        $application->pipe(TrustedHostMiddleware::class);
        $application->pipe(BodyLimitMiddleware::class);
        $application->pipe(SecurityHeadersMiddleware::class);
        if ($portalEnabled) {
            // Recovery composition executes no package code and must remain available while a stale
            // full-runtime process drains, so only the full graph carries the generation fence.
            $application->pipe(ExtensionRuntimeGenerationMiddleware::class);
        }
        $application->pipe(RouteMiddleware::class);
        $application->pipe(ImplicitHeadMiddleware::class);
        $application->pipe(ImplicitOptionsMiddleware::class);
        $application->pipe(MethodNotAllowedMiddleware::class);
        $application->pipe(AdministratorSessionMiddleware::class);
        // A trusted administrator session may be denied by the route gate below, which renders a
        // localized HTML response itself. Enrich the scope before that early-return boundary.
        $application->pipe(TranslationScopeMiddleware::class);
        $application->pipe(AdministratorAuthorizationMiddleware::class);
        if ($portalEnabled) {
            $application->pipe(PortalSessionMiddleware::class);
            // The portal route gate can also return before dispatch once a session is trusted.
            $application->pipe(TranslationScopeMiddleware::class);
            $application->pipe(PortalAuthorizationMiddleware::class);
        }
        $application->pipe(BearerAuthenticationMiddleware::class);
        // Bearer identity is the last authentication source; this pass covers API dispatch while the
        // earlier passes cover localized responses produced by administrator and portal authorization.
        $application->pipe(TranslationScopeMiddleware::class);
        $application->pipe(DispatchMiddleware::class);
        $application->pipe(NotFoundHandler::class);

        $application->get('/', HomePageHandler::class, 'site.home');
        $application->get('/health/live', LivenessHandler::class, 'health.live');
        $application->get('/health/ready', ReadinessHandler::class, 'health.ready');
        $application->get('/metrics', MetricsHandler::class, 'observability.metrics');
        $application->get('/robots.txt', RobotsHandler::class, 'site.robots');
        $application->route(
            '/administrator/login',
            AdministratorLoginHandler::class,
            ['GET', 'POST'],
            'administrator.login',
        );
        if ($portalEnabled) {
            $application->route('/portal/login', PortalLoginHandler::class, ['GET', 'POST'], 'portal.login');
            self::portalRoute($application->get('/portal', PortalHomeHandler::class, 'portal.index'), 'portal.access');
            self::portalRoute($application->post(
                '/portal/dashboard/preferences',
                [PortalCsrfMiddleware::class, PortalDashboardPreferencesHandler::class],
                'portal.dashboard.preferences',
            ), 'portal.access');
            self::portalRoute($application->post(
                '/portal/logout',
                [PortalCsrfMiddleware::class, PortalLogoutHandler::class],
                'portal.logout',
            ), 'portal.access');
            self::portalRoute(
                $application->get('/portal/security', PortalSecurityHandler::class, 'portal.security'),
                'portal.access',
            );
            foreach (
                [
                    ['/portal/security/totp/enroll', 'portal.security.totp.enroll'],
                    ['/portal/security/totp/confirm', 'portal.security.totp.confirm'],
                    ['/portal/security/challenge', 'portal.security.challenge'],
                    ['/portal/security/recovery', 'portal.security.recovery'],
                ] as [$path, $name]
            ) {
                self::portalRoute($application->post(
                    $path,
                    [PortalCsrfMiddleware::class, PortalSecurityHandler::class],
                    $name,
                ), 'portal.access');
            }
            self::portalRoute(
                $application->get('/portal/approvals', PortalApprovalHandler::class, 'portal.approvals'),
                'portal.access',
            );
            self::portalRoute(
                $application->get(
                    '/portal/approvals/{id}',
                    PortalApprovalHandler::class,
                    'portal.approvals.detail',
                ),
                'portal.access',
            );
            foreach (['approve', 'reject', 'revoke'] as $decision) {
                self::portalRoute($application->post(
                    '/portal/approvals/{id}/' . $decision,
                    [PortalCsrfMiddleware::class, PortalApprovalHandler::class],
                    'portal.approvals.' . $decision,
                ), 'portal.access');
            }
            self::portalRoute($application->get(
                '/portal/reports',
                PortalReportHandler::class,
                'portal.reports',
            ), 'portal.access');
            self::portalRoute($application->post(
                '/portal/reports/{report}',
                [PortalCsrfMiddleware::class, PortalReportHandler::class],
                'portal.reports.execute',
            ), 'portal.access');
            self::portalRoute($application->post(
                '/portal/reports/{report}/exports',
                [PortalCsrfMiddleware::class, PortalReportHandler::class],
                'portal.reports.export',
            ), 'portal.access');
            self::portalRoute($application->get(
                '/portal/reports/exports/{artifact}',
                PortalReportHandler::class,
                'portal.report-exports.read',
            ), 'portal.access');
            self::portalRoute($application->get(
                '/portal/reports/exports/{artifact}/download',
                PortalReportHandler::class,
                'portal.report-exports.download',
            ), 'portal.access');
            self::portalRoute(
                $application->get(
                    '/portal/business/operations/{operation}',
                    PortalBusinessSurfaceHandler::class,
                    'portal.business.operation',
                ),
                'portal.access',
            );
            self::portalRoute(
                $application->get(
                    '/portal/business/{definition}/{record}/relationships/{business_relationship}',
                    PortalBusinessSurfaceHandler::class,
                    'portal.business.relationship',
                ),
                'portal.access',
            );
            self::portalRoute($application->post(
                '/portal/business/{definition}/{record}/relationships/{business_relationship}',
                [PortalCsrfMiddleware::class, PortalBusinessSurfaceHandler::class],
                'portal.business.relationship.mutate',
            ), 'portal.access');
            foreach (
                [
                    [
                        '/portal/business/{definition}/{record}/choices/owned-lines/'
                            . '{owned_relationship}/{owned_kind:relations|media}/{owned_field}',
                        'owned-line-field',
                    ],
                    ['/portal/business/{definition}/choices/relations/{related}', 'relations.collection'],
                    ['/portal/business/{definition}/choices/media/{media}', 'media.collection'],
                    ['/portal/business/{definition}/{record}/choices/relations/{related}', 'relations.record'],
                    ['/portal/business/{definition}/{record}/choices/media/{media}', 'media.record'],
                ] as [$path, $name]
            ) {
                self::portalRoute(
                    $application->get(
                        $path,
                        PortalBusinessSurfaceHandler::class,
                        'portal.business.choices.' . $name,
                    ),
                    'portal.access',
                );
            }
            self::portalRoute(
                $application->get(
                    '/portal/business/{definition}/views/{view}',
                    PortalBusinessSurfaceHandler::class,
                    'portal.business.custom-view',
                ),
                'portal.access',
            );
            self::portalRoute(
                $application->get(
                    '/portal/business/{definition}/{record}/views/{view}',
                    PortalBusinessSurfaceHandler::class,
                    'portal.business.custom-record-view',
                ),
                'portal.access',
            );
            foreach (
                [
                    ['/portal/business', 'portal.business'],
                    ['/portal/business/{definition}', 'portal.business.definition'],
                    ['/portal/business/{definition}/{record}', 'portal.business.record'],
                ] as [$path, $name]
            ) {
                self::portalRoute(
                    $application->get($path, PortalBusinessSurfaceHandler::class, $name),
                    'portal.access',
                );
                self::portalRoute($application->post(
                    $path,
                    [PortalCsrfMiddleware::class, PortalBusinessSurfaceHandler::class],
                    $name . '.mutate',
                ), 'portal.access');
            }
        }
        self::administratorRoute(
            $application->get('/administrator', AdministratorDashboardHandler::class, 'administrator.index'),
            'administrator.access',
        );
        self::administratorRoute($application->post(
            '/administrator/dashboard/preferences',
            [AdministratorCsrfMiddleware::class, AdministratorDashboardPreferencesHandler::class],
            'administrator.dashboard.preferences',
        ), 'administrator.access');
        self::administratorRoute($application->post(
            '/administrator/studio/session',
            [AdministratorCsrfMiddleware::class, AdministratorStudioSessionHandler::class],
            'administrator.studio.session',
        ), 'administrator.access');
        self::administratorRoute($application->post(
            '/administrator/studio/ports/{port}/{operation}',
            [AdministratorCsrfMiddleware::class, AdministratorStudioHostHandler::class],
            'administrator.studio.host',
        ), 'administrator.access');
        self::administratorRoute($application->put(
            '/administrator/studio/media/uploads/{upload}',
            AdministratorStudioMediaUploadHandler::class,
            'administrator.studio.media.upload',
        ), 'administrator.access');
        self::administratorRoute(
            $application->get(
                '/administrator/studio/preview',
                AdministratorStudioPreviewDocumentHandler::class,
                'administrator.studio.preview.document',
            ),
            'administrator.access',
        );
        self::administratorRoute(
            $application->get(
                '/administrator/studio/preview/styles.css',
                AdministratorStudioPreviewStylesheetHandler::class,
                'administrator.studio.preview.styles',
            ),
            'administrator.access',
        );
        self::administratorRoute(
            $application->get(
                '/administrator/interface-standard',
                AdministratorInterfaceStandardHandler::class,
                'administrator.interface-standard',
            ),
            'administrator.access',
        );
        self::administratorRoute(
            $application->get(
                '/administrator/business/operations/{operation}',
                AdministratorBusinessSurfaceHandler::class,
                'administrator.business.operation',
            ),
            'administrator.access',
        );
        self::administratorRoute(
            $application->get(
                '/administrator/business/{definition}/{record}/relationships/{business_relationship}',
                AdministratorBusinessSurfaceHandler::class,
                'administrator.business.relationship',
            ),
            'administrator.access',
        );
        self::administratorRoute($application->post(
            '/administrator/business/{definition}/{record}/relationships/{business_relationship}',
            [AdministratorCsrfMiddleware::class, AdministratorBusinessSurfaceHandler::class],
            'administrator.business.relationship.mutate',
        ), 'administrator.access');
        foreach (
            [
                [
                    '/administrator/business/{definition}/{record}/choices/owned-lines/'
                        . '{owned_relationship}/{owned_kind:relations|media}/{owned_field}',
                    'owned-line-field',
                ],
                ['/administrator/business/{definition}/choices/relations/{related}', 'relations.collection'],
                ['/administrator/business/{definition}/choices/media/{media}', 'media.collection'],
                [
                    '/administrator/business/{definition}/{record}/choices/relations/{related}',
                    'relations.record',
                ],
                ['/administrator/business/{definition}/{record}/choices/media/{media}', 'media.record'],
            ] as [$path, $name]
        ) {
            self::administratorRoute(
                $application->get(
                    $path,
                    AdministratorBusinessSurfaceHandler::class,
                    'administrator.business.choices.' . $name,
                ),
                'administrator.access',
            );
        }
        self::administratorRoute(
            $application->get(
                '/administrator/business/{definition}/views/{view}',
                AdministratorBusinessSurfaceHandler::class,
                'administrator.business.custom-view',
            ),
            'administrator.access',
        );
        self::administratorRoute(
            $application->get(
                '/administrator/business/{definition}/{record}/views/{view}',
                AdministratorBusinessSurfaceHandler::class,
                'administrator.business.custom-record-view',
            ),
            'administrator.access',
        );
        foreach (
            [
                ['/administrator/business', 'administrator.business'],
                ['/administrator/business/{definition}', 'administrator.business.definition'],
                ['/administrator/business/{definition}/{record}', 'administrator.business.record'],
            ] as [$path, $name]
        ) {
            $getCapabilities = ['administrator.access'];

            if ($path === '/administrator/business') {
                $getCapabilities[] = 'business.record.browse';
            }

            self::administratorRoute(
                $application->get($path, AdministratorBusinessSurfaceHandler::class, $name),
                ...$getCapabilities,
            );
            self::administratorRoute($application->post(
                $path,
                [AdministratorCsrfMiddleware::class, AdministratorBusinessSurfaceHandler::class],
                $name . '.mutate',
            ), 'administrator.access');
        }
        self::administratorRoute($application->get(
            '/administrator/reports',
            AdministratorReportHandler::class,
            'administrator.reports',
        ), 'business.record.report');
        self::administratorRoute($application->post(
            '/administrator/reports/{report}',
            [AdministratorCsrfMiddleware::class, AdministratorReportHandler::class],
            'administrator.reports.execute',
        ), 'business.record.report');
        self::administratorRoute($application->post(
            '/administrator/reports/{report}/exports',
            [AdministratorCsrfMiddleware::class, AdministratorReportHandler::class],
            'administrator.reports.export',
        ), 'business.record.export');
        self::administratorRoute($application->get(
            '/administrator/reports/exports/{artifact}',
            AdministratorReportHandler::class,
            'administrator.report-exports.read',
        ), 'business.record.export');
        self::administratorRoute($application->get(
            '/administrator/reports/exports/{artifact}/download',
            AdministratorReportHandler::class,
            'administrator.report-exports.download',
        ), 'business.record.export');
        self::administratorRoute($application->get(
            '/administrator/content',
            AdministratorContentListHandler::class,
            'administrator.content',
        ), 'content.read');
        self::administratorRoute($application->get(
            '/administrator/content/new',
            AdministratorContentEditorHandler::class,
            'administrator.content.new',
        ), 'content.create');
        self::administratorRoute($application->get(
            '/administrator/media',
            AdministratorMediaHandler::class,
            'administrator.media',
        ), 'content.read');
        self::administratorRoute($application->post(
            '/administrator/media',
            [AdministratorCsrfMiddleware::class, AdministratorMediaHandler::class],
            'administrator.media.upload',
        ), 'content.update');
        self::administratorRoute($application->post(
            '/administrator/media/{id}/delete',
            [AdministratorCsrfMiddleware::class, AdministratorMediaHandler::class],
            'administrator.media.delete',
        ), 'content.delete');
        self::administratorRoute($application->get(
            '/administrator/content/{id}/edit',
            AdministratorContentEditorHandler::class,
            'administrator.content.edit',
        ), 'content.update');
        self::administratorRoute($application->post(
            '/administrator/content',
            [AdministratorCsrfMiddleware::class, AdministratorCreateContentHandler::class],
            'administrator.content.create',
        ), 'content.create');
        self::administratorRoute($application->post(
            '/administrator/content/{id}',
            [AdministratorCsrfMiddleware::class, AdministratorUpdateContentHandler::class],
            'administrator.content.update',
        ), 'content.update');
        self::administratorRoute($application->post(
            '/administrator/content/{id}/transition',
            [AdministratorCsrfMiddleware::class, AdministratorTransitionContentHandler::class],
            'administrator.content.transition',
        ), 'content.read');
        self::administratorRoute($application->post(
            '/administrator/content/{id}/trash',
            [AdministratorCsrfMiddleware::class, AdministratorTrashContentHandler::class],
            'administrator.content.trash',
        ), 'content.delete');
        self::administratorRoute($application->post(
            '/administrator/content/{id}/restore',
            [AdministratorCsrfMiddleware::class, AdministratorRestoreContentHandler::class],
            'administrator.content.restore',
        ), 'content.restore');
        self::administratorRoute($application->get(
            '/administrator/content-models',
            AdministratorContentModelsHandler::class,
            'administrator.content-models',
        ), 'content.read');
        self::administratorRoute($application->post(
            '/administrator/content-models',
            [AdministratorCsrfMiddleware::class, AdministratorContentModelsHandler::class],
            'administrator.content-models.update',
        ), 'content.update');
        self::administratorRoute($application->get(
            '/administrator/content-models/{id}/versions/{version}/composition',
            AdministratorStudioCompositionHandler::class,
            'administrator.content-models.composition',
        ), 'content.read', 'studio.mode.blueprint');
        self::administratorRoute($application->post(
            '/administrator/content-models/{id}/versions/{version}/composition',
            [AdministratorCsrfMiddleware::class, AdministratorStudioCompositionHandler::class],
            'administrator.content-models.composition.provision',
        ), 'content.read', 'studio.mode.blueprint');
        self::administratorRoute($application->get(
            '/administrator/business-definitions',
            BusinessDefinitionsHandler::class,
            'administrator.business-definitions',
        ), 'content.read');
        self::administratorRoute($application->post(
            '/administrator/business-definitions',
            [AdministratorCsrfMiddleware::class, BusinessDefinitionsHandler::class],
            'administrator.business-definitions.update',
        ), 'content.update');
        self::administratorRoute($application->get(
            '/administrator/business-schema-plans',
            BusinessSchemaPlansHandler::class,
            'administrator.business-schema-plans',
        ), 'business.schema.read');
        self::administratorRoute($application->post(
            '/administrator/business-schema-plans/plan',
            [AdministratorCsrfMiddleware::class, CreateBusinessSchemaPlanHandler::class],
            'administrator.business-schema-plans.plan',
        ), 'business.schema.plan');
        self::administratorRoute($application->post(
            '/administrator/business-schema-plans/{id}/approve',
            [AdministratorCsrfMiddleware::class, ApproveBusinessSchemaPlanHandler::class],
            'administrator.business-schema-plans.approve',
        ), 'business.schema.approve');
        self::administratorRoute($application->post(
            '/administrator/business-schema-plans/{id}/execute',
            [AdministratorCsrfMiddleware::class, ExecuteBusinessSchemaPlanHandler::class],
            'administrator.business-schema-plans.execute',
        ), 'business.schema.execute');
        self::administratorRoute($application->post(
            '/administrator/business-schema-plans/recovery-evidence',
            [AdministratorCsrfMiddleware::class, RecordBusinessSchemaRecoveryEvidenceHandler::class],
            'administrator.business-schema-plans.recovery-evidence',
        ), 'business.schema.recover');
        self::administratorRoute($application->post(
            '/administrator/business-schema-plans/{id}/recover',
            [AdministratorCsrfMiddleware::class, RecoverBusinessSchemaPlanHandler::class],
            'administrator.business-schema-plans.recover',
        ), 'business.schema.recover');
        self::administratorRoute($application->post(
            '/administrator/business-schema-plans/purge',
            [AdministratorCsrfMiddleware::class, CreateBusinessSchemaPurgePlanHandler::class],
            'administrator.business-schema-plans.purge',
        ), 'business.schema.destructive');
        self::administratorRoute($application->post(
            '/administrator/logout',
            [AdministratorCsrfMiddleware::class, AdministratorLogoutHandler::class],
            'administrator.logout',
        ), 'administrator.access');
        self::administratorRoute($application->get(
            '/administrator/extensions',
            AdministratorExtensionsHandler::class,
            'administrator.extensions',
        ), 'extensions.manage');
        self::administratorRoute($application->post(
            '/administrator/extensions',
            [AdministratorCsrfMiddleware::class, AdministratorExtensionsHandler::class],
            'administrator.extensions.install',
        ), 'extensions.manage');
        self::administratorRoute($application->post(
            '/administrator/extensions/action',
            [AdministratorCsrfMiddleware::class, AdministratorExtensionActionHandler::class],
            'administrator.extensions.action',
        ), 'extensions.manage');
        self::administratorRoute($application->get(
            '/administrator/settings',
            AdministratorSettingsHandler::class,
            'administrator.settings',
        ), 'settings.manage');
        self::administratorRoute($application->post(
            '/administrator/settings',
            [AdministratorCsrfMiddleware::class, AdministratorSettingsHandler::class],
            'administrator.settings.update',
        ), 'settings.manage');
        self::administratorRoute($application->get(
            '/administrator/navigation',
            AdministratorNavigationHandler::class,
            'administrator.navigation',
        ), 'navigation.manage');
        self::administratorRoute($application->post(
            '/administrator/navigation',
            [AdministratorCsrfMiddleware::class, AdministratorNavigationHandler::class],
            'administrator.navigation.update',
        ), 'navigation.manage');
        self::administratorRoute($application->get(
            '/administrator/wording',
            AdministratorWordingHandler::class,
            'administrator.wording',
        ), 'localization.overrides.manage');
        self::administratorRoute($application->post(
            '/administrator/wording',
            [AdministratorCsrfMiddleware::class, AdministratorWordingHandler::class],
            'administrator.wording.update',
        ), 'localization.overrides.manage');
        self::administratorRoute($application->get(
            '/administrator/access',
            AdministratorAccessControlHandler::class,
            'administrator.access-control',
        ), 'users.manage');
        self::administratorRoute($application->post(
            '/administrator/access',
            [AdministratorCsrfMiddleware::class, AdministratorAccessControlHandler::class],
            'administrator.access-control.update',
        ), 'users.manage');
        self::administratorRoute($application->get(
            '/administrator/business-security',
            AdministratorBusinessSecurityHandler::class,
            'administrator.business-security',
        ), 'business.security.manage');
        self::administratorRoute($application->post(
            '/administrator/business-security',
            [AdministratorCsrfMiddleware::class, AdministratorBusinessSecurityHandler::class],
            'administrator.business-security.update',
        ), 'business.security.manage');
        self::administratorRoute($application->get(
            '/administrator/automation',
            AdministratorAutomationHandler::class,
            'administrator.automation',
        ), 'automation.manage');
        self::administratorRoute($application->post(
            '/administrator/automation',
            [AdministratorCsrfMiddleware::class, AdministratorAutomationHandler::class],
            'administrator.automation.update',
        ), 'automation.manage');
        $application->get('/pages/{slug}', PublishedContentHandler::class, 'site.content.page');
        $application->get(
            '/studio/styles/{digest}.css',
            StudioPublishedStylesheetHandler::class,
            'site.studio.stylesheet',
        );
        $application->get('/media/{id}/{name}', MediaAssetHandler::class, 'site.media.asset');
        $application->get('/assets/extensions/{path:.+}', ExtensionAssetHandler::class, 'site.extension.asset');
        $application->get('/api/v1', ApiIndexHandler::class, 'api.v1.index');

        self::apiRoute($application->get(
            '/api/v1/openapi.json',
            OpenApiHandler::class,
            'api.v1.openapi',
        ));
        self::apiRoute($application->get(
            '/api/v1/business/reports',
            ReportApiHandler::class,
            'api.v1.business.reports',
        ), 'business.record.report');
        self::apiRoute($application->post(
            '/api/v1/business/reports/{report}',
            ReportApiHandler::class,
            'api.v1.business.reports.execute',
        ), 'business.record.report');
        self::apiRoute($application->post(
            '/api/v1/business/reports/{report}/exports',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                ReportApiHandler::class,
            ],
            'api.v1.business.reports.export',
        ), 'business.record.export');
        self::apiRoute($application->get(
            '/api/v1/business/report-exports/{artifact}',
            ReportApiHandler::class,
            'api.v1.business.report-exports.read',
        ), 'business.record.export');
        self::apiRoute($application->get(
            '/api/v1/business/report-exports/{artifact}/download',
            ReportApiHandler::class,
            'api.v1.business.report-exports.download',
        ), 'business.record.export');
        self::apiRoute($application->get(
            '/api/v1/business/definitions',
            BusinessDefinitionDiscoveryApiHandler::class,
            'api.v1.business.definitions',
        ), 'business.record.browse');
        self::apiRoute($application->get(
            '/api/v1/business/definitions/{definition}',
            BusinessDefinitionDiscoveryApiHandler::class,
            'api.v1.business.definitions.read',
        ), 'business.record.read');
        self::apiRoute($application->get(
            '/api/v1/business/operations/{operation}',
            BusinessOperationStatusApiHandler::class,
            'api.v1.business.operations.read',
        ), 'business.record.read');
        self::apiRoute($application->get(
            '/api/v1/business/approvals',
            BusinessApprovalApiHandler::class,
            'api.v1.business.approvals',
        ));
        self::apiRoute($application->get(
            '/api/v1/business/approvals/{approval}',
            BusinessApprovalApiHandler::class,
            'api.v1.business.approvals.read',
        ));
        self::apiRoute($application->get(
            '/api/v1/business/records/{definition}',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.browse',
        ), 'business.record.browse');
        self::apiRoute($application->post(
            '/api/v1/business/records/{definition}/search',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.search',
        ), 'business.record.browse');
        self::apiRoute($application->post(
            '/api/v1/business/views/{definition}/{view}',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.custom_view',
        ));
        self::apiRoute($application->post(
            '/api/v1/business/records/{definition}',
            [RequireIdempotencyKeyMiddleware::class, BusinessRecordApiHandler::class],
            'api.v1.business.records.create',
        ), 'business.record.create');
        self::apiRoute($application->get(
            '/api/v1/business/records/{definition}/{record}',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.read',
        ), 'business.record.read');
        self::apiRoute($application->post(
            '/api/v1/business/views/{definition}/{record}/{view}',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.custom_record_view',
        ));
        self::apiRoute($application->patch(
            '/api/v1/business/records/{definition}/{record}',
            [
                RequireIdempotencyKeyMiddleware::class,
                RequireIfMatchMiddleware::class,
                BusinessRecordApiHandler::class,
            ],
            'api.v1.business.records.update',
        ), 'business.record.update');
        self::apiRoute($application->delete(
            '/api/v1/business/records/{definition}/{record}',
            [
                RequireIdempotencyKeyMiddleware::class,
                RequireIfMatchMiddleware::class,
                BusinessRecordApiHandler::class,
            ],
            'api.v1.business.records.delete',
        ), 'business.record.delete');
        foreach (['archive', 'restore'] as $lifecycle) {
            self::apiRoute($application->post(
                '/api/v1/business/records/{definition}/{record}/' . $lifecycle,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    RequireIfMatchMiddleware::class,
                    BusinessRecordApiHandler::class,
                ],
                'api.v1.business.records.' . $lifecycle,
            ), 'business.record.' . $lifecycle);
        }
        self::apiRoute($application->get(
            '/api/v1/business/records/{definition}/{record}/history',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.history',
        ), 'business.record.history');
        foreach (['action', 'approval'] as $actionOperation) {
            $suffix = $actionOperation === 'approval' ? '/approval' : '';
            self::apiRoute($application->post(
                '/api/v1/business/records/{definition}/{record}/actions/{action}' . $suffix,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    RequireIfMatchMiddleware::class,
                    BusinessRecordApiHandler::class,
                ],
                'api.v1.business.records.action.' . $actionOperation,
            ), 'business.record.action');
        }
        self::apiRoute($application->post(
            '/api/v1/business/records/{definition}/{record}/relations/{relation}',
            [
                RequireIdempotencyKeyMiddleware::class,
                RequireIfMatchMiddleware::class,
                BusinessRecordApiHandler::class,
            ],
            'api.v1.business.records.relate',
        ), 'business.record.relate');
        self::apiRoute($application->get(
            '/api/v1/business/records/{definition}/{record}/relations/{relation}',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.relation.read',
        ), 'business.record.read');
        self::apiRoute($application->delete(
            '/api/v1/business/records/{definition}/{record}/relations/{relation}/{target}',
            [
                RequireIdempotencyKeyMiddleware::class,
                RequireIfMatchMiddleware::class,
                BusinessRecordApiHandler::class,
            ],
            'api.v1.business.records.unrelate',
        ), 'business.record.relate');
        self::apiRoute($application->put(
            '/api/v1/business/records/{definition}/{record}/relations/{relation}/order',
            [
                RequireIdempotencyKeyMiddleware::class,
                RequireIfMatchMiddleware::class,
                BusinessRecordApiHandler::class,
            ],
            'api.v1.business.records.reorder',
        ), 'business.record.relate');

        $contentCollection = $application->get(
            '/api/v1/content',
            ContentCollectionHandler::class,
            'api.v1.content.collection',
        );
        self::apiRoute($contentCollection, 'content.read');
        $contentCreate = $application->post(
            '/api/v1/content',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                ContentCollectionHandler::class,
            ],
            'api.v1.content.create',
        );
        self::apiRoute($contentCreate, 'content.create');
        $contentItem = $application->get(
            '/api/v1/content/{id}',
            ContentItemHandler::class,
            'api.v1.content.read',
        );
        self::apiRoute($contentItem, 'content.read');
        $contentUpdate = $application->patch(
            '/api/v1/content/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                ContentItemHandler::class,
            ],
            'api.v1.content.update',
        );
        self::apiRoute($contentUpdate, 'content.update');
        $contentDelete = $application->delete(
            '/api/v1/content/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                ContentItemHandler::class,
            ],
            'api.v1.content.trash',
        );
        self::apiRoute($contentDelete, 'content.delete');
        $contentTransition = $application->post(
            '/api/v1/content/{id}/transition',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                ContentTransitionHandler::class,
            ],
            'api.v1.content.transition',
        );
        self::apiRoute($contentTransition, 'content.read');
        $contentRestore = $application->post(
            '/api/v1/content/{id}/restore',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                ContentRestoreHandler::class,
            ],
            'api.v1.content.restore',
        );
        self::apiRoute($contentRestore, 'content.restore');

        foreach (
            [
                '/api/v1/content-types' => ['content-types', '/api/v1/content-types/{id}'],
                '/api/v1/workflows' => ['workflows', '/api/v1/workflows/{id}'],
            ] as $path => [$model, $resourcePath]
        ) {
            self::apiRoute($application->get(
                $path,
                ContentModelApiHandler::class,
                'api.v1.' . $model . '.list',
            ), 'content.read');
            self::apiRoute($application->post(
                $path,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    ContentModelApiHandler::class,
                ],
                'api.v1.' . $model . '.create',
            ), 'content.update');
            self::apiRoute($application->get(
                $resourcePath,
                ContentModelApiHandler::class,
                'api.v1.' . $model . '.read',
            ), 'content.read');
            self::apiRoute($application->patch(
                $resourcePath,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    RequireIfMatchMiddleware::class,
                    ContentModelApiHandler::class,
                ],
                'api.v1.' . $model . '.update',
            ), 'content.update');
        }

        // Business definitions. Reading is content.read and every mutation is content.update,
        // matching the administrator screens these routes are the machine equivalent of.
        self::apiRoute($application->get(
            '/api/v1/business-definitions',
            BusinessDefinitionApiHandler::class,
            'api.v1.business-definitions.list',
        ), 'content.read');
        $definitionReads = [
            '' => 'read',
            '/draft' => 'draft.read',
            '/history' => 'history',
            '/compatibility' => 'compatibility',
        ];
        foreach ($definitionReads as $suffix => $name) {
            self::apiRoute($application->get(
                '/api/v1/business-definitions/{identifier}' . $suffix,
                BusinessDefinitionApiHandler::class,
                'api.v1.business-definitions.' . $name,
            ), 'content.read');
        }
        self::apiRoute($application->put(
            '/api/v1/business-definitions/{identifier}/draft',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                BusinessDefinitionApiHandler::class,
            ],
            'api.v1.business-definitions.draft.save',
        ), 'content.update');
        foreach (['validate', 'publish', 'supersede', 'deprecate', 'reject'] as $action) {
            self::apiRoute($application->post(
                '/api/v1/business-definitions/{identifier}/' . $action,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    BusinessDefinitionApiHandler::class,
                ],
                'api.v1.business-definitions.' . $action,
            ), 'content.update');
        }

        // Schema plans. Each stage is independently grantable, so each route declares only
        // the capability that stage needs; none of them inherits another's authority.
        self::apiRoute($application->get(
            '/api/v1/business-schema-definitions',
            BusinessSchemaApiHandler::class,
            'api.v1.business-schema.definitions',
        ), 'business.schema.read');
        self::apiRoute($application->get(
            '/api/v1/business-schema-plans',
            BusinessSchemaApiHandler::class,
            'api.v1.business-schema-plans.list',
        ), 'business.schema.read');
        self::apiRoute($application->get(
            '/api/v1/business-schema-plans/{planId}',
            BusinessSchemaApiHandler::class,
            'api.v1.business-schema-plans.read',
        ), 'business.schema.read');
        self::apiRoute($application->post(
            '/api/v1/business-schema-plans',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                BusinessSchemaApiHandler::class,
            ],
            'api.v1.business-schema-plans.create',
        ), 'business.schema.plan');
        self::apiRoute($application->post(
            '/api/v1/business-schema-plans/purge',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                BusinessSchemaApiHandler::class,
            ],
            'api.v1.business-schema-plans.purge',
        ), 'business.schema.destructive');
        $planStages = [
            'approve' => 'business.schema.approve',
            'execute' => 'business.schema.execute',
            'recover' => 'business.schema.recover',
        ];
        foreach ($planStages as $action => $capability) {
            self::apiRoute($application->post(
                '/api/v1/business-schema-plans/{planId}/' . $action,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    BusinessSchemaApiHandler::class,
                ],
                'api.v1.business-schema-plans.' . $action,
            ), $capability);
        }

        // Posting periods. Listing and managing are independently grantable, so the read route
        // declares only the read capability while close and reopen each demand the manage one.
        self::apiRoute($application->get(
            '/api/v1/business-periods',
            PostingPeriodApiHandler::class,
            'api.v1.business-periods.list',
        ), 'business.period.read');
        foreach (['close', 'reopen'] as $periodAction) {
            self::apiRoute($application->post(
                '/api/v1/business-periods/' . $periodAction,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    PostingPeriodApiHandler::class,
                ],
                'api.v1.business-periods.' . $periodAction,
            ), 'business.period.manage');
        }

        self::apiRoute($application->get(
            '/api/v1/menus',
            MenuCollectionHandler::class,
            'api.v1.menus.list',
        ), 'navigation.manage');
        self::apiRoute($application->post(
            '/api/v1/menus',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                MenuCollectionHandler::class,
            ],
            'api.v1.menus.create',
        ), 'navigation.manage');
        self::apiRoute($application->get(
            '/api/v1/menus/{id}',
            MenuResourceHandler::class,
            'api.v1.menus.read',
        ), 'navigation.manage');
        self::apiRoute($application->patch(
            '/api/v1/menus/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                MenuResourceHandler::class,
            ],
            'api.v1.menus.update',
        ), 'navigation.manage');
        self::apiRoute($application->delete(
            '/api/v1/menus/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                MenuResourceHandler::class,
            ],
            'api.v1.menus.delete',
        ), 'navigation.manage');
        self::apiRoute($application->get(
            '/api/v1/menus/{menuId}/items',
            MenuItemCollectionHandler::class,
            'api.v1.menu-items.list',
        ), 'navigation.manage');
        self::apiRoute($application->post(
            '/api/v1/menus/{menuId}/items',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                MenuItemCollectionHandler::class,
            ],
            'api.v1.menu-items.create',
        ), 'navigation.manage');
        self::apiRoute($application->get(
            '/api/v1/menu-items/{id}',
            MenuItemResourceHandler::class,
            'api.v1.menu-items.read',
        ), 'navigation.manage');
        self::apiRoute($application->patch(
            '/api/v1/menu-items/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                MenuItemResourceHandler::class,
            ],
            'api.v1.menu-items.update',
        ), 'navigation.manage');
        self::apiRoute($application->delete(
            '/api/v1/menu-items/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                MenuItemResourceHandler::class,
            ],
            'api.v1.menu-items.delete',
        ), 'navigation.manage');

        foreach (
            [
            ['GET', '/api/v1/users', 'api.v1.users.list'],
            ['GET', '/api/v1/roles', 'api.v1.roles.list'],
            ['GET', '/api/v1/tokens', 'api.v1.tokens.list'],
            ] as [$method, $path, $name]
        ) {
            self::apiRoute(
                $application->route($path, AccessControlApiHandler::class, [$method], $name),
                'users.manage',
            );
        }

        self::apiRoute($application->get(
            '/api/v1/settings',
            SiteSettingsApiHandler::class,
            'api.v1.settings.read',
        ), 'settings.manage');
        self::apiRoute($application->put(
            '/api/v1/settings',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                SiteSettingsApiHandler::class,
            ],
            'api.v1.settings.update',
        ), 'settings.manage');
        self::apiRoute($application->get(
            '/api/v1/extensions',
            ExtensionApiHandler::class,
            'api.v1.extensions.list',
        ), 'extensions.manage');
        self::apiRoute($application->get(
            '/api/v1/extension-trust-keys',
            TrustStoreApiHandler::class,
            'api.v1.extension-trust-keys.list',
        ), 'extensions.manage');
        foreach (
            [
            ['POST', '/api/v1/extension-trust-keys', 'api.v1.extension-trust-keys.create'],
            ['POST', '/api/v1/extension-trust-keys/{keyId}/rotate', 'api.v1.extension-trust-keys.rotate'],
            ['DELETE', '/api/v1/extension-trust-keys/{keyId}', 'api.v1.extension-trust-keys.revoke'],
            ] as [$method, $path, $name]
        ) {
            self::apiRoute($application->route(
                $path,
                [
                    TrustLifecycleMiddleware::class,
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    TrustStoreApiHandler::class,
                ],
                [$method],
                $name,
            ), 'extensions.manage');
        }
        foreach (
            [
            ['POST', '/api/v1/extensions/{vendor}/{name}/activate', 'api.v1.extensions.activate'],
            ['POST', '/api/v1/extensions/{vendor}/{name}/disable', 'api.v1.extensions.disable'],
            ['DELETE', '/api/v1/extensions/{vendor}/{name}', 'api.v1.extensions.uninstall'],
            ] as [$method, $path, $name]
        ) {
            self::apiRoute($application->route(
                $path,
                [
                    TrustLifecycleMiddleware::class,
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    ExtensionApiHandler::class,
                ],
                [$method],
                $name,
            ), 'extensions.manage');
        }
        foreach (
            [
            ['POST', '/api/v1/users', 'api.v1.users.create'],
            ['PATCH', '/api/v1/users/{id}', 'api.v1.users.update'],
            ['POST', '/api/v1/roles', 'api.v1.roles.create'],
            ['PUT', '/api/v1/users/{id}/roles/{roleId}', 'api.v1.user-roles.assign'],
            ['DELETE', '/api/v1/users/{id}/roles/{roleId}', 'api.v1.user-roles.revoke'],
            ['POST', '/api/v1/roles/{id}/grants', 'api.v1.role-grants.create'],
            ['DELETE', '/api/v1/grants/{grantId}', 'api.v1.role-grants.revoke'],
            ['POST', '/api/v1/tokens', 'api.v1.tokens.create'],
            ['DELETE', '/api/v1/tokens/{tokenId}', 'api.v1.tokens.revoke'],
            ['POST', '/api/v1/tokens/{tokenId}/rotate', 'api.v1.tokens.rotate'],
            ['DELETE', '/api/v1/users/{id}/tokens', 'api.v1.tokens.emergency-revoke'],
            ['DELETE', '/api/v1/users/{id}/tokens/emergency', 'api.v1.tokens.emergency-revoke-all'],
            ] as [$method, $path, $name]
        ) {
            self::apiRoute($application->route(
                $path,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    in_array($name, ['api.v1.tokens.create', 'api.v1.tokens.rotate'], true)
                        ? SecretOnceIdempotencyMiddleware::class
                        : PersistentIdempotencyMiddleware::class,
                    AccessControlApiHandler::class,
                ],
                [$method],
                $name,
            ), 'users.manage');
        }

        self::apiRoute($application->get(
            '/api/v1/schedules',
            AutomationApiHandler::class,
            'api.v1.schedules.list',
        ), 'automation.manage');
        self::apiRoute($application->post(
            '/api/v1/schedules',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                AutomationApiHandler::class,
            ],
            'api.v1.schedules.create',
        ), 'automation.manage');
        self::apiRoute($application->get(
            '/api/v1/schedules/{id}',
            AutomationApiHandler::class,
            'api.v1.schedules.read',
        ), 'automation.manage');
        foreach (
            [
            ['PATCH', 'api.v1.schedules.update'],
            ['DELETE', 'api.v1.schedules.delete'],
            ] as [$method, $name]
        ) {
            self::apiRoute($application->route(
                '/api/v1/schedules/{id}',
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    RequireIfMatchMiddleware::class,
                    AutomationApiHandler::class,
                ],
                [$method],
                $name,
            ), 'automation.manage');
        }
        self::apiRoute($application->get(
            '/api/v1/jobs',
            AutomationApiHandler::class,
            'api.v1.jobs.list',
        ), 'automation.manage');
        foreach (
            [
            ['/api/v1/jobs/{id}/retry', 'api.v1.jobs.retry'],
            ['/api/v1/jobs/{id}/cancel', 'api.v1.jobs.cancel'],
            ] as [$path, $name]
        ) {
            self::apiRoute($application->post(
                $path,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    AutomationApiHandler::class,
                ],
                $name,
            ), 'automation.manage');
        }

        $planRoute = $application->post(
            '/api/v1/plans',
            [RequireIdempotencyKeyMiddleware::class, PlanPreviewHandler::class],
            'api.v1.plans.preview',
        );
        self::apiRoute($planRoute, 'content.read');

        $mcpRoute = $application->route('/mcp', McpHttpHandler::class, ['GET', 'POST', 'DELETE'], 'mcp');
        self::apiRoute($mcpRoute);
        $mcpRoute->setOptions(array_replace($mcpRoute->getOptions(), [
            BearerAuthenticationMiddleware::OPTION_TOKEN_AUDIENCE => 'kumwe-mcp',
            BearerAuthenticationMiddleware::OPTION_TOKEN_PURPOSE => 'mcp',
        ]));
        $application->route('/mcp', McpHttpHandler::class, ['OPTIONS'], 'mcp.options');
        self::service($container, ActiveExtensionSet::class)->registerRoutes(
            $application,
            self::service($container, AdministratorRenderer::class),
            $portalEnabled ? self::service($container, PortalRenderer::class) : null,
        );
        $application->get('/{path:.+}', PublishedContentHandler::class, 'site.content.path');
    }

    /**
     * Register the automation job handlers, the queue worker and every console command.
     *
     * Each command that acts without an operator present receives a `SystemPrincipal` issued from
     * the kernel proof, which is how unattended work reaches the authorization gateway at all. The
     * administrator theme recovery path is given its own capability object rather than the kernel
     * proof, so ordinary theme mutation cannot reach the recovery behaviour.
     *
     * @param   Container  $container    Container being composed.
     * @param   \stdClass  $kernelProof  Composition-root capability system principals are issued from.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerConsole(Container $container, object $kernelProof): void
    {
        $provenance = $kernelProof;
        $recoveryCapability = new \stdClass();
        $container->share(AdministratorThemeRecovery::class, static fn (
            Container $container,
        ): AdministratorThemeRecovery => new ConsoleAdministratorThemeRecovery(
            new DoctrineAdministratorThemeRecovery(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
                self::service($container, ExtensionRuntimeMapCompiler::class),
                $recoveryCapability,
            ),
            self::service($container, RedisRuntime::class),
            self::service($container, ExtensionRegistryFenceAllocator::class),
            self::service($container, TrustStore::class),
            $recoveryCapability,
        ), true);
        $container->share(PurgeAdministratorSessionsHandler::class, static fn (
            Container $container,
        ): PurgeAdministratorSessionsHandler => new PurgeAdministratorSessionsHandler(
            self::service($container, AdministratorSessionStore::class),
        ), true);
        $container->share(IdempotencyPurger::class, static fn (Container $container): IdempotencyPurger =>
            new DoctrineIdempotencyPurger(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(PurgeIdempotencyRecordsHandler::class, static fn (
            Container $container,
        ): PurgeIdempotencyRecordsHandler => new PurgeIdempotencyRecordsHandler(
            self::service($container, IdempotencyPurger::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(
            ContentStudioAuthoringContextPurger::class,
            static fn (Container $container): ContentStudioAuthoringContextPurger =>
                new DoctrineContentStudioAuthoringContextPurger(
                    self::service($container, Connection::class),
                    self::service($container, TableNames::class),
                    self::service($container, ClockInterface::class),
                ),
            true,
        );
        $container->share(PurgeStudioContentAuthoringContextsHandler::class, static fn (
            Container $container,
        ): PurgeStudioContentAuthoringContextsHandler => new PurgeStudioContentAuthoringContextsHandler(
            self::service($container, ContentStudioAuthoringContextPurger::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(PurgeBusinessRecordIdempotencyHandler::class, static fn (
            Container $container,
        ): PurgeBusinessRecordIdempotencyHandler => new PurgeBusinessRecordIdempotencyHandler(
            self::service($container, BusinessRecordIdempotencyPurger::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(RecordAuditAnchorHandler::class, static fn (
            Container $container,
        ): RecordAuditAnchorHandler => new RecordAuditAnchorHandler(
            self::service($container, AuditAnchorWriter::class),
        ), true);
        $container->share(VerifyAuditTrailHandler::class, static fn (
            Container $container,
        ): VerifyAuditTrailHandler => new VerifyAuditTrailHandler(
            self::service($container, AuditTrailVerifier::class),
        ), true);
        $container->share(EnforceAuditRetentionHandler::class, static fn (
            Container $container,
        ): EnforceAuditRetentionHandler => new EnforceAuditRetentionHandler(
            self::service($container, AuditRetentionService::class),
        ), true);
        $container->share(RotateRecordSecretsHandler::class, static fn (
            Container $container,
        ): RotateRecordSecretsHandler => new RotateRecordSecretsHandler(
            self::service($container, RecordSecretRotation::class),
        ), true);
        $container->share(RebuildExtensionMapHandler::class, static fn (
            Container $container,
        ): RebuildExtensionMapHandler => new RebuildExtensionMapHandler(
            self::service($container, ExtensionRuntimeMapCompiler::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(SynchronizeTrustRevocationsHandler::class, static fn (
            Container $container,
        ): SynchronizeTrustRevocationsHandler => new SynchronizeTrustRevocationsHandler(
            self::service($container, RevocationFeedSynchronizer::class),
        ), true);
        $container->share(TransitionContentHandler::class, static fn (
            Container $container,
        ): TransitionContentHandler => new TransitionContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(JobHandlerRegistry::class, static function (
            Container $container,
        ): JobHandlerRegistry {
            $handlers = [
                self::service($container, PurgeAdministratorSessionsHandler::class),
                self::service($container, PurgeIdempotencyRecordsHandler::class),
                self::service($container, PurgeStudioContentAuthoringContextsHandler::class),
                self::service($container, PurgeBusinessRecordIdempotencyHandler::class),
                self::service($container, RecordAuditAnchorHandler::class),
                self::service($container, VerifyAuditTrailHandler::class),
                self::service($container, EnforceAuditRetentionHandler::class),
                self::service($container, RotateRecordSecretsHandler::class),
                self::service($container, RebuildExtensionMapHandler::class),
                self::service($container, SynchronizeTrustRevocationsHandler::class),
                self::service($container, TransitionContentHandler::class),
                self::service($container, GenerateReportExportHandler::class),
            ];
            foreach (
                self::service(
                    $container,
                    ExtensionContributionRegistrySet::class,
                )->jobs()->executableEntries() as $entry
            ) {
                $definition = $entry['definition'];
                $handler = $entry['implementation'];
                if (
                    !$definition instanceof JobContributionDefinition
                    || !$handler instanceof ContributedJobHandler
                ) {
                    throw new RuntimeException('The trusted job registry contains an invalid executable entry.');
                }
                $handlers[] = new ValidatedContributedJobHandler($definition, $handler);
            }

            return new JobHandlerRegistry($handlers);
        }, true);
        $container->share(GlobalJobPrincipals::class, static fn (): GlobalJobPrincipals => new GlobalJobPrincipals(
            SystemPrincipal::issue($provenance, SystemIdentity::InstallationMaintenance),
            SystemPrincipal::issue($provenance, SystemIdentity::ExtensionMaterializer),
        ), true);
        $container->share(Worker::class, static fn (Container $container): Worker => new Worker(
            self::service($container, JobQueue::class),
            self::service($container, JobHandlerRegistry::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, ResourceSiteOwnership::class),
            SystemPrincipal::issue($provenance, SystemIdentity::Worker),
            self::service($container, JobExecutionScope::class),
            self::service($container, GlobalJobPrincipals::class),
        ), true);
        $container->share(FilesystemDemoManifestCatalog::class, static fn (): FilesystemDemoManifestCatalog =>
            new FilesystemDemoManifestCatalog(dirname(__DIR__, 2)), true);
        $container->share(DoctrineDemoProfileLedger::class, static fn (
            Container $container,
        ): DoctrineDemoProfileLedger => new DoctrineDemoProfileLedger(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->alias(DemoProfileLedger::class, DoctrineDemoProfileLedger::class);
        $container->share(DemoContentProfileInstaller::class, static fn (
            Container $container,
        ): DemoContentProfileInstaller => new DemoContentProfileInstaller(
            self::service($container, ContentService::class),
            self::service($container, NavigationService::class),
            self::service($container, SiteSettings::class),
            self::service($container, DemoProfileLedger::class),
            self::service($container, TransactionManager::class),
        ), true);
        $container->share(VdmBusinessDemoInstaller::class, static fn (
            Container $container,
        ): VdmBusinessDemoInstaller => new VdmBusinessDemoInstaller(
            self::service($container, BusinessDefinitionService::class),
            self::service($container, BusinessSchemaService::class),
            self::service($container, BusinessRecordService::class),
            new VdmBusinessManifestProjector(),
            new VdmBusinessOperationGuard(),
            self::service($container, DemoProfileLedger::class),
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(DemoProfileInstaller::class, static fn (
            Container $container,
        ): DemoProfileInstaller => new DemoProfileInstaller(
            self::service($container, ApplicationConfiguration::class),
            self::service($container, FilesystemDemoManifestCatalog::class),
            self::service($container, DemoContentProfileInstaller::class),
            self::service($container, VdmBusinessDemoInstaller::class),
            self::service($container, DemoProfileLedger::class),
            SystemPrincipal::issue($provenance, SystemIdentity::ProfileInstaller),
        ), true);
        $container->alias(DemoProfileReconciler::class, DemoProfileInstaller::class);
        // The console's one translator binding: catalogue-backed, without the database-held override
        // layers, so a recovery command still renders its wording when the database is unreachable.
        // The console negotiates no locale, so messages resolve at the source locale.
        $container->share(Output::class, static fn (Container $container): Output => StreamOutput::standard(
            new CatalogueTranslator(
                self::service($container, MessageCatalogueRepository::class),
                new ArrayMessageOverrideRepository(),
                self::service($container, MessagePatternFormatter::class),
                self::service($container, ActiveLocale::class),
                self::service($container, SupportedLocales::class),
            ),
        ), true);
        $container->share(MigrateCommand::class, static fn (Container $container): MigrateCommand =>
            new MigrateCommand(
                self::service($container, MigrationRunner::class),
                self::service($container, DemoProfileReconciler::class),
                self::service($container, ExtensionRuntimeMapCompiler::class),
                SystemPrincipal::issue($provenance, SystemIdentity::Migration),
            ), true);
        $container->share(MaterializeExtensionRuntimeCommand::class, static fn (
            Container $container,
        ): MaterializeExtensionRuntimeCommand => new MaterializeExtensionRuntimeCommand(
            self::service($container, ExtensionRuntimeMapCompiler::class),
            self::service($container, ExtensionInstallReconciler::class),
        ), true);
        $container->share(WatchExtensionRuntimeCommand::class, static fn (
            Container $container,
        ): WatchExtensionRuntimeCommand => new WatchExtensionRuntimeCommand(
            self::service($container, ExtensionRuntimeMapCompiler::class),
            self::service($container, ExtensionInstallReconciler::class),
        ), true);
        $container->share(MigrationStatusCommand::class, static fn (Container $container): MigrationStatusCommand =>
            new MigrationStatusCommand(
                self::service($container, MigrationRunner::class),
                SystemPrincipal::issue($provenance, SystemIdentity::Migration),
            ), true);
        $container->share(RecoverMigrationLockCommand::class, static fn (
            Container $container,
        ): RecoverMigrationLockCommand => new RecoverMigrationLockCommand(
            self::service($container, MigrationLockRecoveryService::class),
            SystemPrincipal::issue($provenance, SystemIdentity::Migration),
        ), true);
        $container->share(HealthCheckCommand::class, static fn (Container $container): HealthCheckCommand =>
            new HealthCheckCommand(self::service($container, ReadinessProbe::class)), true);
        $container->share(CreateAdministratorCommand::class, static fn (
            Container $container,
        ): CreateAdministratorCommand => new CreateAdministratorCommand(
            self::service($container, AdministratorIdentityGateway::class),
            SystemPrincipal::issue($provenance, SystemIdentity::Bootstrap),
        ), true);
        $container->share(RecoverCredentialsCommand::class, static fn (
            Container $container,
        ): RecoverCredentialsCommand => new RecoverCredentialsCommand(
            self::service($container, AccessControlService::class),
            self::service($container, AccessControlRepository::class),
            SystemPrincipal::issue($provenance, SystemIdentity::CredentialRecovery),
        ), true);
        $container->share(DemoAccessProvisioner::class, static fn (
            Container $container,
        ): DemoAccessProvisioner => new DemoAccessProvisioner(
            self::service($container, AccessControlService::class),
            self::service($container, AccessControlRepository::class),
            self::service($container, BusinessSecurityAdministrationRepository::class),
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(DemoAccessCommand::class, static fn (
            Container $container,
        ): DemoAccessCommand => new DemoAccessCommand(
            self::service($container, ApplicationConfiguration::class),
            self::service($container, FilesystemDemoManifestCatalog::class),
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, DemoAccessProvisioner::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(DemoExampleExtensionInstaller::class, static fn (
            Container $container,
        ): DemoExampleExtensionInstaller => new DemoExampleExtensionInstaller(
            dirname(__DIR__, 2),
            self::service($container, ExtensionManager::class),
            self::service($container, TrustStore::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(DemoExamplesCommand::class, static fn (
            Container $container,
        ): DemoExamplesCommand => new DemoExamplesCommand(
            self::service($container, ApplicationConfiguration::class),
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, DemoExampleExtensionInstaller::class),
        ), true);
        $container->share(DemoInstallCommand::class, static fn (
            Container $container,
        ): DemoInstallCommand => new DemoInstallCommand(
            self::service($container, ApplicationConfiguration::class),
            self::service($container, FilesystemDemoManifestCatalog::class),
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, DemoAccessProvisioner::class),
            self::service($container, DemoExampleExtensionInstaller::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(DemoProfileExporter::class, static fn (
            Container $container,
        ): DemoProfileExporter => new DemoProfileExporter(
            self::service($container, ContentService::class),
            self::service($container, NavigationService::class),
            self::service($container, SiteSettings::class),
            self::service($container, DemoProfileLedger::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(DemoBusinessProfileExporter::class, static fn (
            Container $container,
        ): DemoBusinessProfileExporter => new DemoBusinessProfileExporter(
            self::service($container, BusinessDefinitionService::class),
            self::service($container, BusinessRecordService::class),
            self::service($container, AccessControlService::class),
            self::service($container, BusinessSecurityAdministrationRepository::class),
            self::service($container, FilesystemDemoManifestCatalog::class),
            self::service($container, ApplicationConfiguration::class),
            self::service($container, DemoProfileLedger::class),
        ), true);
        $container->share(DemoExportCommand::class, static fn (
            Container $container,
        ): DemoExportCommand => new DemoExportCommand(
            self::service($container, ApplicationConfiguration::class),
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, DemoProfileExporter::class),
            self::service($container, DemoBusinessProfileExporter::class),
        ), true);
        $container->share(CreateAccessTokenCommand::class, static fn (
            Container $container,
        ): CreateAccessTokenCommand => new CreateAccessTokenCommand(
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, ConsoleAuthorizer::class),
            self::service($container, MembershipDirectory::class),
        ), true);
        $container->share(ListExtensionsCommand::class, static fn (
            Container $container,
        ): ListExtensionsCommand => new ListExtensionsCommand(
            self::service($container, ExtensionManager::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(InstallExtensionCommand::class, static fn (
            Container $container,
        ): InstallExtensionCommand => new InstallExtensionCommand(
            self::service($container, ExtensionManager::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ScaffoldExtensionCommand::class, static fn (
            Container $container,
        ): ScaffoldExtensionCommand => new ScaffoldExtensionCommand(
            self::service($container, ComponentScaffolder::class),
        ), true);
        $container->share(BuildExtensionCommand::class, static fn (
            Container $container,
        ): BuildExtensionCommand => new BuildExtensionCommand(
            self::service($container, DeterministicPackageBuilder::class),
        ), true);
        $container->share(InspectExtensionCommand::class, static fn (
            Container $container,
        ): InspectExtensionCommand => new InspectExtensionCommand(
            self::service($container, PackageInspector::class),
        ), true);
        $container->share(SignExtensionCommand::class, static fn (
            Container $container,
        ): SignExtensionCommand => new SignExtensionCommand(
            self::service($container, PackageSigner::class),
        ), true);
        $container->share(RunExtensionConformanceCommand::class, static fn (
            Container $container,
        ): RunExtensionConformanceCommand => new RunExtensionConformanceCommand(
            self::service($container, StaticConformanceRunner::class),
        ), true);
        $container->share(ActivateExtensionCommand::class, static fn (
            Container $container,
        ): ActivateExtensionCommand => new ActivateExtensionCommand(
            self::service($container, ExtensionManager::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(DisableExtensionCommand::class, static fn (
            Container $container,
        ): DisableExtensionCommand => new DisableExtensionCommand(
            self::service($container, ExtensionManager::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(UninstallExtensionCommand::class, static fn (
            Container $container,
        ): UninstallExtensionCommand => new UninstallExtensionCommand(
            self::service($container, ExtensionManager::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(RecoverAdministratorThemeCommand::class, static fn (
            Container $container,
        ): RecoverAdministratorThemeCommand => new RecoverAdministratorThemeCommand(
            self::service($container, AdministratorThemeRecovery::class),
        ), true);
        $container->share(QueueWorkCommand::class, static fn (Container $container): QueueWorkCommand =>
            new QueueWorkCommand(
                self::service($container, Worker::class),
                SystemPrincipal::issue($provenance, SystemIdentity::Worker),
                self::service($container, ExtensionRuntimeMapCompiler::class),
                self::service($container, RuntimeMaterializationState::class),
                self::service($container, QueueRuntimePolicyCatalog::class),
            ), true);
        $container->share(ScheduleRunCommand::class, static fn (Container $container): ScheduleRunCommand =>
            new ScheduleRunCommand(
                self::service($container, Scheduler::class),
                SystemPrincipal::issue($provenance, SystemIdentity::Scheduler),
                self::service($container, ExtensionRuntimeMapCompiler::class),
                self::service($container, RuntimeMaterializationState::class),
            ), true);
        $container->share(IntegrationWorkCommand::class, static fn (
            Container $container,
        ): IntegrationWorkCommand => new IntegrationWorkCommand(
            self::service($container, OutboxDispatcher::class),
            self::service($container, ProcessWorkDispatcher::class),
            self::service($container, ExtensionRuntimeMapCompiler::class),
            self::service($container, RuntimeMaterializationState::class),
        ), true);
        $container->share(ReportCommand::class, static fn (Container $container): ReportCommand =>
            new ReportCommand(
                self::service($container, ReportService::class),
                self::service($container, ExportService::class),
                self::service($container, ConsoleAuthorizer::class),
                self::service($container, ReportApiPresenter::class),
            ), true);
        $container->share(ConsoleAuthorizer::class, static fn (Container $container): ConsoleAuthorizer =>
            new ConsoleAuthorizer(self::service($container, AccessTokenVerifier::class)), true);
        $container->share(ManageIntegrationsCommand::class, static fn (
            Container $container,
        ): ManageIntegrationsCommand => new ManageIntegrationsCommand(
            self::service($container, IntegrationOperationsService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ManageAutomationCommand::class, static fn (
            Container $container,
        ): ManageAutomationCommand => new ManageAutomationCommand(
            self::service($container, AutomationManagementService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ManageContentCommand::class, static fn (Container $container): ManageContentCommand =>
            new ManageContentCommand(
                self::service($container, ContentService::class),
                self::service($container, ConsoleAuthorizer::class),
            ), true);
        $container->share(ManageContentModelsCommand::class, static fn (
            Container $container,
        ): ManageContentModelsCommand => new ManageContentModelsCommand(
            self::service($container, ContentModelService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ManageBusinessDefinitionsCommand::class, static fn (
            Container $container,
        ): ManageBusinessDefinitionsCommand => new ManageBusinessDefinitionsCommand(
            self::service($container, BusinessDefinitionService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(BusinessRecordConsolePresenter::class, new BusinessRecordConsolePresenter(), true);
        $container->share(BusinessConsoleFailureMapper::class, new BusinessConsoleFailureMapper(), true);
        $container->share(ManageBusinessRecordsCommand::class, static fn (
            Container $container,
        ): ManageBusinessRecordsCommand => new ManageBusinessRecordsCommand(
            self::service($container, BusinessRecordService::class),
            self::service($container, BusinessSurfaceService::class),
            self::service($container, BusinessSurfaceCatalog::class),
            self::service($container, BusinessRecordQueryFactory::class),
            self::service($container, BusinessRecordProjector::class),
            self::service($container, BusinessOperationStatusService::class),
            self::service($container, BusinessApprovalSurfaceService::class),
            self::service($container, ConsoleAuthorizer::class),
            self::service($container, BusinessRecordConsolePresenter::class),
            self::service($container, BusinessConsoleFailureMapper::class),
        ), true);
        $container->share(ManageBusinessSchemaCommand::class, static fn (
            Container $container,
        ): ManageBusinessSchemaCommand => new ManageBusinessSchemaCommand(
            self::service($container, BusinessSchemaService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ManagePostingPeriodsCommand::class, static fn (
            Container $container,
        ): ManagePostingPeriodsCommand => new ManagePostingPeriodsCommand(
            self::service($container, PostingPeriodService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ManageNavigationCommand::class, static fn (
            Container $container,
        ): ManageNavigationCommand => new ManageNavigationCommand(
            self::service($container, NavigationService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ManageSettingsCommand::class, static fn (Container $container): ManageSettingsCommand =>
            new ManageSettingsCommand(
                self::service($container, SiteSettings::class),
                self::service($container, ConsoleAuthorizer::class),
            ), true);
        $container->share(ManageAccessCommand::class, static fn (Container $container): ManageAccessCommand =>
            new ManageAccessCommand(
                self::service($container, AccessControlService::class),
                self::service($container, AdministratorIdentityGateway::class),
                self::service($container, ConsoleAuthorizer::class),
            ), true);
        $container->share(ManageTrustStoreCommand::class, static fn (
            Container $container,
        ): ManageTrustStoreCommand => new ManageTrustStoreCommand(
            self::service($container, TrustStore::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(VerifyAuditTrailCommand::class, static fn (
            Container $container,
        ): VerifyAuditTrailCommand => new VerifyAuditTrailCommand(
            self::service($container, AuditTrailVerifier::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ExportAuditTrailCommand::class, static fn (
            Container $container,
        ): ExportAuditTrailCommand => new ExportAuditTrailCommand(
            self::service($container, AuditTrailExporter::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(RotateRecordSecretsCommand::class, static fn (
            Container $container,
        ): RotateRecordSecretsCommand => new RotateRecordSecretsCommand(
            self::service($container, RecordSecretRotation::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(McpServeCommand::class, static fn (Container $container): McpServeCommand =>
            new McpServeCommand(
                self::service($container, KumweMcpServerFactory::class),
                self::service($container, KumweMcpHandlers::class),
                self::service($container, AccessTokenVerifier::class),
                self::service($container, LoggerInterface::class),
            ), true);
        $container->share(ConsoleApplication::class, static fn (Container $container): ConsoleApplication =>
            new ConsoleApplication([
                self::service($container, MigrateCommand::class),
                self::service($container, MaterializeExtensionRuntimeCommand::class),
                self::service($container, WatchExtensionRuntimeCommand::class),
                self::service($container, MigrationStatusCommand::class),
                self::service($container, RecoverMigrationLockCommand::class),
                self::service($container, HealthCheckCommand::class),
                self::service($container, CreateAdministratorCommand::class),
                self::service($container, RecoverCredentialsCommand::class),
                self::service($container, DemoAccessCommand::class),
                self::service($container, DemoExamplesCommand::class),
                self::service($container, DemoInstallCommand::class),
                self::service($container, DemoExportCommand::class),
                self::service($container, CreateAccessTokenCommand::class),
                self::service($container, ListExtensionsCommand::class),
                self::service($container, InstallExtensionCommand::class),
                self::service($container, ScaffoldExtensionCommand::class),
                self::service($container, BuildExtensionCommand::class),
                self::service($container, InspectExtensionCommand::class),
                self::service($container, SignExtensionCommand::class),
                self::service($container, RunExtensionConformanceCommand::class),
                self::service($container, ActivateExtensionCommand::class),
                self::service($container, DisableExtensionCommand::class),
                self::service($container, UninstallExtensionCommand::class),
                self::service($container, RecoverAdministratorThemeCommand::class),
                self::service($container, QueueWorkCommand::class),
                self::service($container, ScheduleRunCommand::class),
                self::service($container, IntegrationWorkCommand::class),
                self::service($container, ManageIntegrationsCommand::class),
                self::service($container, ReportCommand::class),
                self::service($container, ManageAutomationCommand::class),
                self::service($container, ManageContentCommand::class),
                self::service($container, ManageContentModelsCommand::class),
                self::service($container, ManageBusinessDefinitionsCommand::class),
                self::service($container, ManageBusinessRecordsCommand::class),
                self::service($container, ManageBusinessSchemaCommand::class),
                self::service($container, ManagePostingPeriodsCommand::class),
                self::service($container, ManageNavigationCommand::class),
                self::service($container, ManageSettingsCommand::class),
                self::service($container, ManageAccessCommand::class),
                self::service($container, ManageTrustStoreCommand::class),
                self::service($container, VerifyAuditTrailCommand::class),
                self::service($container, ExportAuditTrailCommand::class),
                self::service($container, RotateRecordSecretsCommand::class),
                self::service($container, McpServeCommand::class),
            ], self::service($container, Output::class)), true);
    }

    /**
     * Register the Model Context Protocol server factory, its handlers and its session store.
     *
     * Sessions are files under `storage/sessions/mcp` with a one-hour lifetime, so a horizontally
     * scaled deployment has to give every replica the same volume for a session to survive a request
     * that lands on another instance. Both the HTTP transport and the `mcp:serve` console command
     * resolve the same handlers, so the two transports expose an identical capability surface.
     *
     * @param   Container  $container  Container being composed.
     * @param   string     $root       Absolute path of the repository root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerMcp(Container $container, string $root): void
    {
        $container->share(McpCapabilityCatalog::class, new McpCapabilityCatalog(), true);
        $container->share(McpMutationGuard::class, static fn (Container $container): McpMutationGuard =>
            new McpMutationGuard(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, ClockInterface::class),
                self::service($container, TransactionManager::class),
            ), true);
        $container->share(BusinessMcpHandlers::class, static fn (Container $container): BusinessMcpHandlers =>
            new BusinessMcpHandlers(
                self::service($container, BusinessSurfaceCatalog::class),
                self::service($container, BusinessSurfaceService::class),
                self::service($container, BusinessMutationPlanService::class),
                self::service($container, McpMutationGuard::class),
                self::service($container, BusinessOperationStatusService::class),
                self::service($container, BusinessSurfaceService::class),
            ), true);
        $container->share(ReportMcpHandlers::class, static fn (Container $container): ReportMcpHandlers =>
            new ReportMcpHandlers(
                self::service($container, ReportService::class),
                self::service($container, ExportService::class),
                self::service($container, ReportApiPresenter::class),
            ), true);
        $container->share(SessionStoreInterface::class, static fn (Container $container): SessionStoreInterface =>
            new FileSessionStore(
                $root . '/storage/sessions/mcp',
                3_600,
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(KumweMcpHandlers::class, static fn (Container $container): KumweMcpHandlers =>
            new KumweMcpHandlers(
                self::service($container, McpCapabilityCatalog::class),
                self::service($container, ContentService::class),
                self::service($container, NavigationService::class),
                self::service($container, AccessControlService::class),
                self::service($container, SiteSettings::class),
                self::service($container, ExtensionManager::class),
                self::service($container, TrustStore::class),
                self::service($container, AutomationManagementService::class),
                self::service($container, BusinessDefinitionService::class),
                self::service($container, BusinessSchemaService::class),
                self::service($container, BusinessMcpHandlers::class),
                self::service($container, ReportMcpHandlers::class),
                self::service($container, McpMutationGuard::class),
                self::service($container, ClockInterface::class),
                self::service($container, AuthorizationGateway::class),
                extensionRuntime: self::service($container, ExtensionExecutionGate::class),
            ), true);
        $container->share(KumweMcpServerFactory::class, static fn (Container $container): KumweMcpServerFactory =>
            new KumweMcpServerFactory(
                self::service($container, McpCapabilityCatalog::class),
                sessions: self::service($container, SessionStoreInterface::class),
                logger: self::service($container, LoggerInterface::class),
            ), true);
    }

    /**
     * Require an authenticated administrator session holding every listed capability.
     *
     * `AdministratorAuthorizationMiddleware` rejects any `/administrator` route that declares no
     * capability, so every such route other than the login form is registered through here. The
     * capabilities are conjunctive: the session must hold all of them.
     *
     * @param   Route   $route         Route returned by the router, to attach the requirement to.
     * @param   string  $capabilities  Capability names the session must hold, all of them required.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function administratorRoute(Route $route, string ...$capabilities): void
    {
        $route->setOptions([
            AdministratorAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES => $capabilities,
        ]);
    }

    /**
     * Attach conjunctive portal capability requirements to one route.
     *
     * @param   Route   $route         Matched route receiving the authorization option.
     * @param   string  $capabilities  Capability names the portal actor must hold through the gateway.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function portalRoute(Route $route, string ...$capabilities): void
    {
        $route->setOptions([
            PortalAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES => $capabilities,
        ]);
    }

    /**
     * Require a bearer token issued for the HTTP API and holding every listed capability.
     *
     * Besides the capabilities this pins the audience to `kumwe-http` and the purpose to `api`, so a
     * token minted for another transport is refused even when it carries the right capability. Pass
     * no capability for a route that only needs an authenticated token.
     *
     * @param   Route   $route         Route returned by the router, to attach the requirement to.
     * @param   string  $capabilities  Capability names the presented token must carry, all required.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function apiRoute(Route $route, string ...$capabilities): void
    {
        $route->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => $capabilities,
            BearerAuthenticationMiddleware::OPTION_TOKEN_AUDIENCE => 'kumwe-http',
            BearerAuthenticationMiddleware::OPTION_TOKEN_PURPOSE => 'api',
        ]);
    }

    /**
     * Decode the checked-in OpenAPI document and prove its root is a string-keyed JSON object.
     *
     * @param   string  $json  Checked-in OpenAPI JSON bytes.
     *
     * @return  array<string, mixed>  Decoded contract root.
     *
     * @throws  RuntimeException  When the decoded contract root is not a JSON object.
     *
     * @since   2.0.0
     */
    private static function decodeOpenApiObject(string $json): array
    {
        $contract = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($contract) || array_is_list($contract)) {
            throw new RuntimeException('The checked-in core OpenAPI contract is not an object.');
        }
        foreach (array_keys($contract) as $key) {
            if (!is_string($key)) {
                throw new RuntimeException('The checked-in core OpenAPI contract is not an object.');
            }
        }
        /** @var array<string, mixed> $contract */

        return $contract;
    }

    /**
     * Prove definitions from one generic owner-aware registry have the surface's exact contract type.
     *
     * @template T of ContributionDefinition
     *
     * @param   list<ContributionDefinition>  $definitions  Generic active declarations in stable order.
     * @param   class-string<T>               $type         Required declaration type for the surface.
     *
     * @return  list<T>  Declarations proven to implement the requested surface contract.
     *
     * @throws  RuntimeException  When a registry contains a declaration for another surface.
     *
     * @since   2.0.0
     */
    private static function contributionDefinitions(array $definitions, string $type): array
    {
        $resolved = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof $type) {
                throw new RuntimeException(sprintf(
                    'An extension contribution registry contains a definition other than %s.',
                    $type,
                ));
            }
            $resolved[] = $definition;
        }

        return $resolved;
    }

    /**
     * The reviewed qualification of the exact Studio deployment this composition root ships.
     *
     * These digests are reviewed constants, not values read back from disk: `composer studio:corpus`,
     * `npm run check:studio-release` and the architecture suite prove the committed release record,
     * registry pin, materialized first-party catalog and Producer's pinned browser module still carry
     * exactly these coordinates, and a re-pin that moves any of them must be reviewed here again
     * before contextual authoring re-enables.
     *
     * @return  StudioContextualAuthoringQualification  Exact App-owned host qualification.
     *
     * @since   2.0.0
     */
    private static function studioContextualAuthoringQualification(): StudioContextualAuthoringQualification
    {
        return new StudioContextualAuthoringQualification(
            '0.1.0-beta.3',
            'c1860c64d1a4ce1e6d9b96ea94a2d63df42e8ebaaf423ed5b4fa4dd841ed39a4',
            '5ba19c85b1a12071cabfd0f5588040072de7ffcf2891ff6ebc15c1c4eb5103b5',
            '92aa7f78924faa93e520d36a77dc14723ff644780db8aae689013668f6b76e4f',
            'sha256-bQLLnuYhQprCbuB1LX5Yb09/n+KVS6xB+M7Mdh2/D34=',
        );
    }

    /**
     * Resolve a shared service and prove it is of the requested type.
     *
     * The container hands back an untyped value, so every factory in this class resolves through here.
     * That keeps the container's contents typed for static analysis and turns a misregistered
     * service into an immediate composition failure rather than a wrong object reaching a
     * constructor.
     *
     * @template T of object
     *
     * @param   Container        $container  Container to resolve from.
     * @param   class-string<T>  $service    Service identifier, always the class or interface name.
     *
     * @return  T  The resolved service, guaranteed to be an instance of the requested type.
     *
     * @throws  RuntimeException  When the container resolves the identifier to a value of another type.
     *
     * @since   2.0.0
     */
    private static function service(Container $container, string $service): object
    {
        $resolved = $container->get($service);

        if (!$resolved instanceof $service) {
            throw new RuntimeException(sprintf('Container service "%s" resolved to an invalid value.', $service));
        }

        return $resolved;
    }
}
