<?php

namespace Appwrite\Platform\Modules\Insights\Services;

use Appwrite\Platform\Modules\Insights\Http\Cta\Trigger as TriggerCta;
use Appwrite\Platform\Modules\Insights\Http\Insights\Create as CreateInsight;
use Appwrite\Platform\Modules\Insights\Http\Insights\Delete as DeleteInsight;
use Appwrite\Platform\Modules\Insights\Http\Insights\Dismiss as DismissInsight;
use Appwrite\Platform\Modules\Insights\Http\Insights\Get as GetInsight;
use Appwrite\Platform\Modules\Insights\Http\Insights\Update as UpdateInsight;
use Appwrite\Platform\Modules\Insights\Http\Insights\XList as ListInsights;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(CreateInsight::getName(), new CreateInsight());
        $this->addAction(GetInsight::getName(), new GetInsight());
        $this->addAction(ListInsights::getName(), new ListInsights());
        $this->addAction(UpdateInsight::getName(), new UpdateInsight());
        $this->addAction(DismissInsight::getName(), new DismissInsight());
        $this->addAction(DeleteInsight::getName(), new DeleteInsight());

        $this->addAction(TriggerCta::getName(), new TriggerCta());
    }
}
