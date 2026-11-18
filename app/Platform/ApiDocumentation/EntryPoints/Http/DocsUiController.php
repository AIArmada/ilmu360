<?php

declare(strict_types=1);

namespace App\Platform\ApiDocumentation\EntryPoints\Http;

use App\Http\Controllers\Controller;
use App\Platform\ApiDocumentation\ApiDocumentationConfigFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

class DocsUiController extends Controller
{
    public function __invoke(
        ApiDocumentationConfigFactory $configFactory,
    ): View {
        $config = $configFactory->make();

        return view('api.documentation.docs', [
            'specUrl' => Route::has('scramble.docs.document')
                ? route('scramble.docs.document')
                : '/docs.json',
            'config' => $config,
        ]);
    }
}
