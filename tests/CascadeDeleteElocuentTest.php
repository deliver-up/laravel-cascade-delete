<?php

namespace Tests;

use Tests\Models\Photo;
use Tests\Models\Video;
use Illuminate\Support\Facades\DB;

class CascadeDeleteElocuentTest extends TestCase
{
    public function test_it_can_delete_relations_with_morph_many()
    {
        $photo1 = Photo::with('options')->first();
        $photo2 = Photo::with('options')->skip(1)->first();

        $this->assertGreaterThan(0, count($photo1->options));
        $this->assertGreaterThan(0, count($photo2->options));

        Photo::first()->delete();

        $this->assertEquals(
            0,
            DB::table('options')->where([
                'optionable_type' => Photo::class,
                'optionable_id' => $photo1->id,
            ])->count()
        );

        $this->assertEquals(
            count($photo2->options),
            DB::table('options')->where([
                'optionable_type' => Photo::class,
                'optionable_id' => $photo2->id,
            ])->count()
        );
    }

    public function test_it_can_delete_relations_with_morph_to_many()
    {
        $video1 = Video::with('tags')->first();
        $video2 = Video::with('tags')->skip(1)->first();

        $this->assertGreaterThan(0, count($video1->tags));
        $this->assertGreaterThan(0, count($video2->tags));

        Video::first()->delete();

        $this->assertEquals(
            0,
            DB::table('taggables')->where([
                'taggable_type' => Video::class,
                'taggable_id' => $video1->id,
            ])->count()
        );

        $this->assertEquals(
            count($video2->tags),
            DB::table('taggables')->where([
                'taggable_type' => Video::class,
                'taggable_id' => $video2->id,
            ])->count()
        );
    }
}