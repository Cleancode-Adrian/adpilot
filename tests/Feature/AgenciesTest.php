<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Agencje (zleceniodawcy) i przypisane do nich strony. */
class AgenciesTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $name, ?Agency $agency = null): Client
    {
        return Client::create(['name' => $name, 'website_url' => 'https://'.strtolower($name).'.pl', 'currency' => 'PLN', 'has_care' => true, 'agency_id' => $agency?->id]);
    }

    public function test_create_agency_and_list_its_sites(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/agencies', ['name' => 'Studio XYZ', 'website_url' => 'studio-xyz.pl', 'contact_email' => 'anna@studio-xyz.pl', 'white_label' => true])
            ->assertRedirect();
        $agency = Agency::firstOrFail();
        $this->assertSame('https://studio-xyz.pl', $agency->website_url);
        $this->assertTrue($agency->white_label);

        $this->site('Piekarnia', $agency);
        $this->site('Wlasny');

        $this->get('/agencies')->assertInertia(fn (Assert $p) => $p->component('agencies/index')
            ->has('agencies', 1)
            ->where('agencies.0.sites.0.name', 'Piekarnia')
            ->where('unassignedCount', 1));

        $this->get("/agencies/{$agency->id}")->assertInertia(fn (Assert $p) => $p->component('agencies/show')
            ->has('clients', 1)
            ->where('clients.0.name', 'Piekarnia')
            ->where('unassigned.0.name', 'Wlasny'));

        $this->get('/clients')->assertInertia(fn (Assert $p) => $p->where('agencies.0.name', 'Studio XYZ')
            ->where('clients', fn ($rows) => collect($rows)->firstWhere('name', 'Piekarnia')['agency_name'] === 'Studio XYZ'));
    }

    public function test_client_form_assigns_agency_and_attach_detach(): void
    {
        $this->actingAs(User::factory()->create());
        $agency = Agency::create(['name' => 'Studio XYZ']);

        $this->get("/clients/create?agency={$agency->id}")->assertInertia(fn (Assert $p) => $p->where('agencyId', $agency->id)->has('agencies', 1));
        $this->post('/clients', ['name' => 'Kwiaciarnia', 'website_url' => 'kwiaty.pl', 'has_general' => true, 'agency_id' => $agency->id]);
        $this->assertSame($agency->id, Client::where('name', 'Kwiaciarnia')->value('agency_id'));

        $own = $this->site('Wlasny');
        $this->post("/agencies/{$agency->id}/attach", ['client_id' => $own->id]);
        $this->assertSame($agency->id, $own->fresh()->agency_id);

        $this->delete("/agencies/{$agency->id}/clients/{$own->id}");
        $this->assertNull($own->fresh()->agency_id);

        $other = Agency::create(['name' => 'Inna']);
        $this->delete("/agencies/{$other->id}/clients/{$own->id}")->assertNotFound();
    }

    public function test_deleting_agency_keeps_sites(): void
    {
        $this->actingAs(User::factory()->create());
        $agency = Agency::create(['name' => 'Studio XYZ']);
        $site = $this->site('Piekarnia', $agency);

        $this->delete("/agencies/{$agency->id}")->assertRedirect('/agencies');

        $this->assertModelMissing($agency);
        $this->assertNull($site->fresh()->agency_id);
    }

    public function test_archived_agency_still_selectable_for_its_clients(): void
    {
        $this->actingAs(User::factory()->create());
        $agency = Agency::create(['name' => 'Stara', 'archived_at' => now()]);
        Agency::create(['name' => 'Aktywna']);
        $site = $this->site('Piekarnia', $agency);

        $this->get('/clients/create')->assertInertia(fn (Assert $p) => $p->has('agencies', 1));
        $this->get("/clients/{$site->id}/edit")->assertInertia(fn (Assert $p) => $p->has('agencies', 2));
    }
}
