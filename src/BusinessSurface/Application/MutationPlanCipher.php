<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use Kumwe\Secret\Contract\EnvelopeCipher;

/**
 * The cipher mutation-plan tokens are sealed with, deliberately not the one record secrets use.
 *
 * Plan tokens and record secrets need the same primitive and nothing else in common. A token is opaque,
 * handed to a browser, and dead within five minutes; a record secret is written once and expected to open
 * years later. While both went through one shared `EnvelopeCipher` instance they also shared a key and a key
 * identifier, which meant a record-key rotation would have re-keyed live plan tokens and a move to a
 * managed KMS would have dragged plan tokens into it.
 *
 * This type is what keeps them apart at compile time rather than by wiring discipline: the container binds
 * it to a ring derived under its own label with its own identifier, and `BusinessMutationPlanService` can
 * no longer be handed the record cipher by mistake. The associated data already separated the two domains;
 * this separates the keys, so neither rotation can entangle the other.
 *
 * @since  2.0.0
 */
interface MutationPlanCipher extends EnvelopeCipher
{
}
