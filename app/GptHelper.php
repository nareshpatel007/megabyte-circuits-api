<?php

namespace App;

class GptHelper
{
    // Call OpenAI Chat API with JSON response format
    public static function chatgptModelCall($system_prompt, $user_prompt, $max_tokens = 1500, $model = 'gpt-4.1')
    {
        try {
            // Get use from env
            $api_key = env('OPENAI_API_KEY');

            // Check if API key is empty
            if (empty($api_key)) {
                return null;
            }

            // Prepare the request data
            $data = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $user_prompt],
                ],
                'max_tokens' => (int) $max_tokens,
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object'],
            ];

            // Initialize cURL
            $ch = curl_init('https://api.openai.com/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $api_key,
                ],
                CURLOPT_POSTFIELDS => json_encode($data),
            ]);

            // Execute cURL
            $response = curl_exec($ch);
            $curl_error = curl_error($ch);
            curl_close($ch);

            // Check for cURL errors or empty response
            if ($curl_error || empty($response)) {
                return null;
            }

            // Decode the response
            $response_arr = json_decode($response, true);
            $content = $response_arr['choices'][0]['message']['content'] ?? null;

            // Check if content is empty
            if (empty($content)) {
                return null;
            }

            // Strip markdown fences
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content));
            $decoded = json_decode($content, true);

            if (is_array($decoded) && !empty($decoded) && isset($response_arr['usage'])) {
                $decoded['_usage'] = $response_arr['usage'];
            }

            return $decoded;
        } catch (\Throwable $th) {
            return null;
        }
    }
}