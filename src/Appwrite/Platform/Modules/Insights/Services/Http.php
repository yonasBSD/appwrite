<?php

namespace Appwrite\Platform\Modules\Insights\Services;

use Appwrite\Platform\Modules\Insights\Http\Insights\Delete as DeleteInsight;
use Appwrite\Platform\Modules\Insights\Http\Insights\Get as GetInsight;
use Appwrite\Platform\Modules\Insights\Http\Insights\Update as UpdateInsight;
use Appwrite\Platform\Modules\Insights\Http\Insights\XList as ListInsights;
use Appwrite\Platform\Modules\Insights\Http\Manager\Insights\Create as CreateInsight;
use Appwrite\Platform\Modules\Insights\Http\Reports\Create as CreateReport;
use Appwrite\Platform\Modules\Insights\Http\Reports\Delete as DeleteReport;
use Appwrite\Platform\Modules\Insights\Http\Reports\Get as GetReport;
use Appwrite\Platform\Modules\Insights\Http\Reports\Update as UpdateReport;
use Appwrite\Platform\Modules\Insights\Http\Reports\XList as ListReports;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(CreateReport::getName(), new CreateReport());
        $this->addAction(GetReport::getName(), new GetReport());
        $this->addAction(ListReports::getName(), new ListReports());
        $this->addAction(UpdateReport::getName(), new UpdateReport());
        $this->addAction(DeleteReport::getName(), new DeleteReport());

        // Manager-only ingestion (hidden from SDKs, /v1/manager/insights).
        $this->addAction(CreateInsight::getName(), new CreateInsight());

        $this->addAction(GetInsight::getName(), new GetInsight());
        $this->addAction(ListInsights::getName(), new ListInsights());
        $this->addAction(UpdateInsight::getName(), new UpdateInsight());
        $this->addAction(DeleteInsight::getName(), new DeleteInsight());
    }
}
