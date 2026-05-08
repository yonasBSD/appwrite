<?php

namespace Appwrite\Platform\Modules\Insights\Enums;

enum InsightSeverity: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case CRITICAL = 'critical';
}
