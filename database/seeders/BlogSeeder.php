<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogTag;
use Illuminate\Support\Str;

class BlogSeeder extends Seeder
{
    public function run(): void
    {
        $catDesign = BlogCategory::firstOrCreate(['slug' => 'design'], ['name' => 'Design', 'description' => 'PCB Design and Layout Guidelines']);
        $catMfg    = BlogCategory::firstOrCreate(['slug' => 'manufacturing'], ['name' => 'Manufacturing', 'description' => 'Fabrication and Manufacturing Insights']);
        $catAssy   = BlogCategory::firstOrCreate(['slug' => 'assembly'], ['name' => 'Assembly', 'description' => 'SMT and Component Assembly Practices']);

        $tagSI    = BlogTag::firstOrCreate(['slug' => 'signal-integrity'], ['name' => 'Signal Integrity']);
        $tagProto = BlogTag::firstOrCreate(['slug' => 'pcb-fab'], ['name' => 'PCB Fabrication']);
        $tagSMT   = BlogTag::firstOrCreate(['slug' => 'smt'], ['name' => 'SMT']);

        $posts = [
            [
                'title'          => 'PCB Design Tips for Signal Integrity',
                'slug'           => 'pcb-design-tips-signal-integrity',
                'excerpt'        => 'Essential trace routing, impedance matching, and ground plane strategies for high-speed designs.',
                'content'        => '<h2>Controlled Impedance Matching</h2><p>To prevent signal reflections, the characteristic impedance of a transmission line must match the source and load impedances. A mismatch creates a reflection boundary, causing signal distortion, overshoot, undershoot, and increased electromagnetic emissions.</p><blockquote>Always consult your PCB manufacturer\'s stackup details before finalizing trace widths. Copper thickness, dielectric thickness, and dielectric constants directly determine the required trace widths for 50Ω single-ended or 100Ω differential pairs.</blockquote><h2>Crosstalk Mitigation Strategies</h2><p>Crosstalk is the unwanted electromagnetic coupling between adjacent traces. It is dictated by the distance between traces, the distance to the reference plane, and the length over which the traces run parallel.</p>',
                'featured_image' => 'https://images.unsplash.com/photo-1518770660439-4636190af475?q=80&w=600&auto=format&fit=crop',
                'status'         => 'published',
                'category_id'    => $catDesign->id,
                'category'       => 'Design',
                'tags'           => 'Signal Integrity,High-Speed Layout',
                'reading_time'   => '6 min read',
                'is_featured'    => true,
                'published_at'   => now()->subDays(5),
                'meta_title'     => 'PCB Design Tips for Signal Integrity - MegaByte Circuits',
                'meta_description' => 'Essential trace routing, impedance matching, and ground plane strategies for high-speed designs written by senior layout engineers.',
                'og_image'       => 'https://images.unsplash.com/photo-1518770660439-4636190af475?q=80&w=600&auto=format&fit=crop',
            ],
            [
                'title'          => 'Complete PCB Manufacturing Process Guide',
                'slug'           => 'complete-pcb-manufacturing-process-guide',
                'excerpt'        => 'From bare substrate to finished board: understanding every step of the PCB fabrication process.',
                'content'        => '<h2>Step 1: Front-End Engineering & CAM Check</h2><p>Before production begins, manufacturing engineers perform a Design for Manufacture (DFM) analysis. Computer-Aided Manufacturing (CAM) tools inspect files for trace spacing, minimum hole sizes, ring dimensions, and overall board dimensions to prevent manufacturing failures.</p><h2>Step 2: Lamination and Pressing</h2><p>Once inner layers are etched and verified, they are stacked with prepreg sheets and outer copper foil under high press heat.</p>',
                'featured_image' => 'https://images.unsplash.com/photo-1617791160536-598cf32026fb?q=80&w=600&auto=format&fit=crop',
                'status'         => 'published',
                'category_id'    => $catMfg->id,
                'category'       => 'Manufacturing',
                'tags'           => 'Manufacturing,PCB Fabrication',
                'reading_time'   => '8 min read',
                'is_featured'    => false,
                'published_at'   => now()->subDays(8),
                'meta_title'     => 'Complete PCB Manufacturing Process Guide - MegaByte Circuits',
                'meta_description' => 'From bare substrate to finished board: understanding every step of the PCB fabrication process.',
                'og_image'       => 'https://images.unsplash.com/photo-1617791160536-598cf32026fb?q=80&w=600&auto=format&fit=crop',
            ],
            [
                'title'          => 'SMT Assembly Best Practices',
                'slug'           => 'smt-assembly-best-practices',
                'excerpt'        => 'Stencil design, paste deposition, reflow profiles, and defect prevention in surface-mount assembly.',
                'content'        => '<h2>Solder Paste Printing: The Critical 60%</h2><p>Studies show that over 60% of SMT assembly defects originate during the solder paste printing stage. Stencil aperture design, stencil thickness, squeegee pressure, and paste chemistry must be carefully optimized.</p><h2>Reflow Profile Optimization</h2><p>The reflow profile consists of preheat, soak, reflow, and cooling zones.</p>',
                'featured_image' => 'https://images.unsplash.com/photo-1607604276583-eef5d076aa5f?q=80&w=600&auto=format&fit=crop',
                'status'         => 'published',
                'category_id'    => $catAssy->id,
                'category'       => 'Assembly',
                'tags'           => 'Assembly,SMT',
                'reading_time'   => '7 min read',
                'is_featured'    => false,
                'published_at'   => now()->subDays(12),
                'meta_title'     => 'SMT Assembly Best Practices - MegaByte Circuits',
                'meta_description' => 'Stencil design, paste deposition, reflow profiles, and defect prevention in surface-mount assembly.',
                'og_image'       => 'https://images.unsplash.com/photo-1607604276583-eef5d076aa5f?q=80&w=600&auto=format&fit=crop',
            ],
        ];

        foreach ($posts as $data) {
            Blog::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
