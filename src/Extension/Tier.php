<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Extension;

/**
 * The assistant's tiers (DELIVERY_PLAN §1, BEHAVIOUR_SPEC B-ACC-9). A tool is offered only to a user whose tier is at
 * least the tool's (P3.2-1); tiers are cumulative. Each tier is a Concrete task permission key (NeuronPermissions).
 * Tier 4 (free-form code) is never inside the CMS, so it has no case here.
 */
enum Tier: int
{
    /** Edit pages and blocks, set attribute values, read the site: `use_neuron_ai`. */
    case CONTENT = 1;

    /** Define the site's structure (attribute definitions and sets, topic trees; Express later): `neuron_ai_tier_configure`. */
    case CONFIGURE = 2;

    /** Generate packages from an app spec (Phase 7), and external write tools that declare no tier: `neuron_ai_tier_generate`. */
    case GENERATE = 3;
}
