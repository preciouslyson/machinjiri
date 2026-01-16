<?php

namespace Mlangeni\Machinjiri\Core\Database\Factory;

class FactoryBuilder
{
    /**
     * Create common factory definitions.
     */
    public static function defineCommonFactories(): void
    {
        // User factory example
        Factory::define('User', function (Generator $faker) {
            return [
                'name' => $faker->name,
                'email' => $faker->unique()->safeEmail,
                'password' => password_hash('password', PASSWORD_DEFAULT),
                'email_verified_at' => $faker->optional(0.8)->dateTimeThisYear,
                'created_at' => $faker->dateTimeThisYear,
                'updated_at' => $faker->dateTimeThisYear,
            ];
        });

        // Post factory example
        Factory::define('Post', function (Generator $faker) {
            return [
                'title' => $faker->sentence(6),
                'content' => $faker->paragraphs(3, true),
                'published' => $faker->boolean(70),
                'views' => $faker->numberBetween(0, 10000),
                'created_at' => $faker->dateTimeThisYear,
                'updated_at' => $faker->dateTimeThisYear,
            ];
        });

        // Category factory example
        Factory::define('Category', function (Generator $faker) {
            return [
                'name' => $faker->words(2, true),
                'slug' => $faker->slug,
                'description' => $faker->optional()->sentence,
                'created_at' => $faker->dateTimeThisYear,
                'updated_at' => $faker->dateTimeThisYear,
            ];
        });
    }

    /**
     * Define common states.
     */
    public static function defineCommonStates(): void
    {
        // User states
        Factory::state('User', 'admin', function (Generator $faker) {
            return [
                'is_admin' => true,
                'role' => 'admin',
            ];
        });

        Factory::state('User', 'unverified', function (Generator $faker) {
            return [
                'email_verified_at' => null,
            ];
        });

        Factory::state('User', 'inactive', function (Generator $faker) {
            return [
                'active' => false,
                'deactivated_at' => $faker->dateTimeThisYear,
            ];
        });

        // Post states
        Factory::state('Post', 'published', function (Generator $faker) {
            return [
                'published' => true,
                'published_at' => $faker->dateTimeThisYear,
            ];
        });

        Factory::state('Post', 'draft', function (Generator $faker) {
            return [
                'published' => false,
                'published_at' => null,
            ];
        });

        Factory::state('Post', 'popular', function (Generator $faker) {
            return [
                'views' => $faker->numberBetween(10000, 100000),
                'likes' => $faker->numberBetween(100, 1000),
            ];
        });
    }

    /**
     * Create dummy data for common tables.
     */
    public static function seedCommonData(array $counts = []): void
    {
        $defaultCounts = [
            'users' => 10,
            'posts' => 50,
            'categories' => 5,
        ];

        $counts = array_merge($defaultCounts, $counts);

        // Seed categories first
        $categories = Factory::create('Category', $counts['categories']);

        // Seed users
        $users = Factory::create('User', $counts['users']);

        // Seed posts with random categories and authors
        $posts = [];
        for ($i = 0; $i < $counts['posts']; $i++) {
            $posts[] = Factory::make('Post', [
                'user_id' => $users[array_rand($users)]['id'] ?? null,
                'category_id' => $categories[array_rand($categories)]['id'] ?? null,
            ], true);
        }

        return [
            'users' => $users,
            'posts' => $posts,
            'categories' => $categories,
        ];
    }
}