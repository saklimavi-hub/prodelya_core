<?php

namespace App\Http\Controllers;

use App\Models\OrderItemWorkForm;
use App\Services\PublicWorkFormTrackingDataBuilder;
use Illuminate\View\View;

class PublicWorkFormTrackingController extends Controller
{
    public function __construct(
        protected PublicWorkFormTrackingDataBuilder $dataBuilder
    ) {
    }

    public function show(string $token): View
    {
        $workForm = OrderItemWorkForm::query()
            ->where('public_tracking_token', $token)
            ->where('status', 'active')
            ->firstOrFail();

        $workForm->loadMissing([
            'tenant',
            'attachments',
            'activityLogs.attachment',
        ]);

        return view('public.work-forms.track', $this->dataBuilder->build($workForm));
    }
}
