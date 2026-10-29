@include('errors.page', ['status' => method_exists($exception ?? null, 'getStatusCode') ? $exception->getStatusCode() : 400])
