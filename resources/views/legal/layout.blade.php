<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | {{ config('app.name', 'Noor Academy') }}</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .prose h1 { font-size: 2.25rem; font-weight: 700; margin-bottom: 1.5rem; }
        .prose h2 { font-size: 1.5rem; font-weight: 600; margin-top: 2rem; margin-bottom: 1rem; }
        .prose p { margin-bottom: 1rem; line-height: 1.6; color: #374151; }
        .prose ul { list-style-type: disc; padding-left: 1.5rem; margin-bottom: 1rem; }
        .prose li { margin-bottom: 0.5rem; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased">
    <div class="max-w-7xl mx-auto px-4 py-12 sm:px-6 lg:px-8">
        <div class="bg-white shadow-xl rounded-2xl overflow-hidden border border-gray-100">
            <!-- Header -->
            <div class="bg-amber-600 px-8 py-10 text-white text-center">
                <h1 class="text-3xl font-bold uppercase tracking-wide">{{ $title }}</h1>
                {{-- <p class="mt-2 text-green-100 opacity-80">Last Updated: {{ date('F d, Y') }}</p> --}}
            </div>

            <!-- Content -->
            <div class="px-8 py-12 prose max-w-none">
                @yield('content')
            </div>

            <!-- Footer -->
            <div class="bg-gray-50 px-8 py-6 border-t border-gray-100 text-center text-sm text-gray-500">
                &copy; {{ date('Y') }} {{ config('app.name', 'Noor Academy') }}. All rights reserved.
            </div>
        </div>

        {{-- <div class="mt-8 text-center">
            <a href="/" class="text-indigo-600 hover:text-indigo-800 font-medium transition duration-150 ease-in-out">
                &larr; Back to Home
            </a>
        </div>
    </div> --}}
</body>
</html>
