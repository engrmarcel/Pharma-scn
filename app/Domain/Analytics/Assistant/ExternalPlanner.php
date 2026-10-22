<?php

namespace App\Domain\Analytics\Assistant;

/**
 * A planner that runs outside the platform. Used only when the platform is configured for
 * it and the organization has consented; it receives the redacted question and the report
 * catalogue, never report data.
 */
interface ExternalPlanner extends QuestionPlanner {}
