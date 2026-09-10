<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Authority-preserving boundary that supplies values referenced by Blueprint bindings.
 *
 * @since  2.0.0
 */
interface StudioPreviewBindingSource
{
    /**
     * Prove the draft belongs to the bound host resource and return only authorized values.
     *
     * @param   ExecutionContext           $context   Authenticated App request authority.
     * @param   StudioHostSessionSnapshot  $snapshot  Live resource and permission binding.
     * @param   StudioPreviewDraft         $draft     Exact unpublished Blueprint being rendered.
     *
     * @return  StudioPreviewBindingValues  Canonical values safe for binding evaluation.
     *
     * @throws  StudioPreviewRefused  When resource ownership, projection, or Blueprint binding is unavailable.
     *
     * @since   2.0.0
     */
    public function resolve(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewDraft $draft,
    ): StudioPreviewBindingValues;
}
