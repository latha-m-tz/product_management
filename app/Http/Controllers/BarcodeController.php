<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class BarcodeController extends Controller
{
    /**
     * Get product info by barcode
     * 
     * @param string $barcode
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProductInfo($barcode)
    {
        if (!$barcode) {
            return response()->json(['error' => 'Barcode is required'], 400);
        }

        try {
            // Use UPCItemDB trial API
            // Disable SSL verification for local/testing environment
            $response = Http::withoutVerifying()->get("https://api.upcitemdb.com/prod/trial/{$barcode}");

            if ($response->failed()) {
                return response()->json([
                    'error' => 'Failed to fetch product info',
                    'status' => $response->status(),
                    'body' => $response->body(),
                ], $response->status());
            }

            $data = $response->json();

            // Check if 'items' exist
            $item = $data['items'][0] ?? null;
            if (!$item) {
                return response()->json([
                    'barcode' => $barcode,
                    'title' => 'Not found',
                    'brand' => '',
                    'model' => '',
                    'images' => [],
                    'raw' => $data,
                ]);
            }

            // Return relevant info
            return response()->json([
                'barcode' => $barcode,
                'title'   => $item['title'] ?? 'Not found',
                'brand'   => $item['brand'] ?? '',
                'model'   => $item['model'] ?? '',
                'images'  => $item['images'] ?? [],
                'raw'     => $data,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}