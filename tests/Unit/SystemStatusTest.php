<?php

namespace Oilstone\SystemStatus\Tests\Unit;

use Oilstone\SystemStatus\Exceptions\UnknownCategory;
use Oilstone\SystemStatus\Exceptions\UnknownMonitor;
use Oilstone\SystemStatus\Probe;
use Oilstone\SystemStatus\Rating;
use Oilstone\SystemStatus\Result;
use Oilstone\SystemStatus\SystemStatus;
use Oilstone\SystemStatus\Tests\Fixtures\FakeMonitor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SystemStatusTest extends TestCase
{
    public function test_monitors_are_built_only_when_checked_and_fresh_each_time(): void
    {
        $built = 0;
        $status = (new SystemStatus())->add(function () use (&$built) {
            $built++;

            return new FakeMonitor();
        });

        $this->assertSame(0, $built);

        $status->check();
        $status->check();

        $this->assertSame(2, $built);
    }

    public function test_categories_are_checked_in_order(): void
    {
        $status = (new SystemStatus())->addMany([
            'connections' => [['class' => FakeMonitor::class, 'name' => 'A'], ['class' => FakeMonitor::class, 'name' => 'B']],
            'services' => [['class' => FakeMonitor::class, 'name' => 'C']],
        ]);

        $this->assertSame(['connections', 'services'], $status->categories());
        $this->assertTrue($status->has('services'));
        $this->assertFalse($status->has('other'));
        $this->assertSame(['A', 'B', 'C'], $this->names($status->check()->results()));
        $this->assertSame(['C'], $this->names($status->check('services')->results()));
        $this->assertSame(['C', 'A', 'B'], $this->names($status->check([' services', 'connections', 'services', ''])->results()));
    }

    public function test_monitors_default_to_the_default_category(): void
    {
        $status = (new SystemStatus())->add(new FakeMonitor());

        $this->assertSame(['default'], $status->categories());
    }

    public function test_requesting_unknown_or_no_categories_throws(): void
    {
        $status = (new SystemStatus())->add(new FakeMonitor(), 'connections');

        try {
            $status->check(['connections', 'nope', 'other']);
            $this->fail('Expected UnknownCategory');
        } catch (UnknownCategory $e) {
            $this->assertSame('Unknown monitor categories: nope, other', $e->getMessage());
        }

        $this->expectException(UnknownCategory::class);
        $this->expectExceptionMessage('No monitor categories were requested');

        $status->check([' ', '']);
    }

    public function test_an_empty_instance_passes_when_checking_everything(): void
    {
        $this->assertTrue((new SystemStatus())->passes());
    }

    public function test_results_carry_ratings_notes_metadata_and_durations(): void
    {
        $result = $this->checkOne(new FakeMonitor(['check' => function (Probe $probe) {
            $probe->rate(Rating::Acceptable, 'Fine')->degrade('Slow')->rate(Rating::Optimal)->note('Noted')->meta('items', 3);
            usleep(2000);
        }]), 'services');

        $this->assertSame('services', $result->category);
        $this->assertSame('Fake', $result->name);
        $this->assertSame(Rating::Degraded, $result->rating);
        $this->assertSame(['Fine', 'Slow', 'Noted'], $result->notes);
        $this->assertSame(['items' => 3], $result->meta);
        $this->assertNull($result->error);
        $this->assertGreaterThanOrEqual(2.0, $result->duration);
    }

    public function test_the_rating_is_the_worse_of_the_probe_and_the_duration(): void
    {
        $slow = new FakeMonitor(['thresholds' => [0 => 'optimal'], 'otherwise' => 'acceptable']);
        $degraded = new FakeMonitor(['thresholds' => [0 => 'optimal'], 'check' => fn (Probe $probe) => $probe->degrade('Busy')]);

        $this->assertSame(Rating::Acceptable, $this->checkOne($slow)->rating);
        $this->assertSame(Rating::Degraded, $this->checkOne($degraded)->rating);
    }

    public function test_failures_never_escape_a_check(): void
    {
        $status = (new SystemStatus())
            ->add(new FakeMonitor(['check' => fn () => throw new RuntimeException('Down')]))
            ->add(new FakeMonitor(['check' => fn (Probe $probe) => $probe->fail('Probe failed')]))
            ->add(['type' => 'file', 'name' => 'Unbuildable'])
            ->add(['type' => 'nope'])
            ->add(new class() extends FakeMonitor
            {
                public function rate(float $duration): Rating
                {
                    throw new RuntimeException('Rating broke');
                }
            });

        $results = $status->check()->results();

        $this->assertSame(['Down', 'Probe failed', 'Unbuildable is missing required configuration: path', 'No monitor type named "nope" has been registered', 'Rating broke'], array_map(fn (Result $result) => $result->errorMessage(), $results));
        $this->assertSame(['Fake', 'Fake', 'Unbuildable', 'nope', 'Fake'], $this->names($results));

        foreach ($results as $result) {
            $this->assertSame(Rating::Failed, $result->rating);
        }
    }

    public function test_checks_can_stop_at_the_first_bad_result(): void
    {
        $status = (new SystemStatus())
            ->add(new FakeMonitor(['name' => 'A']))
            ->add(new FakeMonitor(['name' => 'B', 'check' => fn (Probe $probe) => $probe->degrade('Slow')]))
            ->add(new FakeMonitor(['name' => 'C', 'check' => fn () => throw new RuntimeException('Down')]));

        $this->assertSame(['A', 'B', 'C'], $this->names($status->check()->results()));
        $this->assertSame(['A', 'B'], $this->names($status->check(null, Rating::Degraded)->results()));
        $this->assertFalse($status->passes());
        $this->assertFalse($status->passes(null, Rating::Degraded));
        $this->assertFalse($status->passes(['default'], Rating::Failed));
        $this->assertTrue((new SystemStatus())->add(new FakeMonitor(['check' => fn (Probe $probe) => $probe->degrade('Slow')]))->passes());
    }

    public function test_types_can_be_registered(): void
    {
        $status = (new SystemStatus())->extend('fake', FakeMonitor::class)->add('fake');

        $this->assertTrue($status->factory()->hasType('fake'));
        $this->assertSame(['Fake'], $this->names($status->check()->results()));
    }

    protected function checkOne(mixed $definition, string $category = 'default'): Result
    {
        return (new SystemStatus())->add($definition, $category)->check()->results()[0];
    }

    /**
     * @param list<Result> $results
     * @return list<string>
     */
    public function test_monitors_with_keys_have_ids(): void
    {
        $status = (new SystemStatus())->addMany([
            'services' => [
                'payments' => ['class' => FakeMonitor::class, 'name' => 'Payments'],
                ['class' => FakeMonitor::class, 'name' => 'Search'],
            ],
            'third.party' => ['mail' => ['class' => FakeMonitor::class, 'name' => 'Mail', 'check' => fn (Probe $probe) => $probe->fail('Mail is down')]],
        ]);

        $this->assertTrue($status->hasMonitor('services.payments'));
        $this->assertTrue($status->hasMonitor('services.0'));
        $this->assertTrue($status->hasMonitor('third.party.mail'));
        $this->assertFalse($status->hasMonitor('services.mail'));
        $this->assertFalse($status->hasMonitor('services'));

        $this->assertSame('Payments', $status->checkMonitor('services.payments')->name);
        $this->assertSame('services', $status->checkMonitor('services.payments')->category);
        $this->assertSame('Search', $status->checkMonitor('services.0')->name);
        $this->assertSame(Rating::Failed, $status->checkMonitor('third.party.mail')->rating);
        $this->assertSame('Mail', $status->make('third.party.mail')->name());
        $this->assertSame('Payments', $status->check('services')->results()[0]->name);

        $this->expectException(UnknownMonitor::class);
        $this->expectExceptionMessage('No monitor has the id "services.mail"');

        $status->checkMonitor('services.mail');
    }

    public function test_a_monitor_added_with_an_existing_key_replaces_it(): void
    {
        $status = (new SystemStatus())
            ->add(['class' => FakeMonitor::class, 'name' => 'Old'], 'services', 'payments')
            ->add(['class' => FakeMonitor::class, 'name' => 'Other'], 'services')
            ->add(['class' => FakeMonitor::class, 'name' => 'New'], 'services', 'payments');

        $this->assertSame(['New', 'Other'], $this->names($status->check()->results()));
    }

    public function test_definitions_are_listed_without_building_them(): void
    {
        $status = (new SystemStatus())
            ->add(fn () => throw new RuntimeException('Built'), 'connections')
            ->add(['class' => FakeMonitor::class], 'services', 'payments');

        $this->assertSame(['services', 'connections'], array_keys($status->definitions(['services', 'connections'])));
        $this->assertSame(['payments'], array_keys($status->definitions('services')['services']));

        $this->expectException(UnknownCategory::class);

        $status->definitions('nope');
    }

    protected function names(array $results): array
    {
        return array_map(fn (Result $result) => $result->name, $results);
    }
}
