<?php

namespace Appwrite\Platform\Modules\Insights\Services;

use Appwrite\Platform\Modules\Insights\Http\Insights\Get as GetInsight;
use Appwrite\Platform\Modules\Insights\Http\Insights\XList as ListInsights;
use Appwrite\Platform\Modules\Insights\Http\Reports\Get as GetReport;
use Appwrite\Platform\Modules\Insights\Http\Reports\XList as ListReports;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(GetReport::getName(), new GetReport());
        $this->addAction(ListReports::getName(), new ListReports());

        $this->addAction(GetInsight::getName(), new GetInsight());
        $this->addAction(ListInsights::getName(), new ListInsights());
    }
}
