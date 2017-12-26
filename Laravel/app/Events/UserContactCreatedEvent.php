<?php

namespace App\Events;

use App\Events\Event;
use App\Addressbook;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class UserContactCreatedEvent extends Event
{
    use SerializesModels;

    public $contact;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Addressbook $contact)
    {
        $this->contact = $contact;
    }

    /**
     * Get the channels the event should be broadcast on.
     *
     * @return array
     */
    public function broadcastOn()
    {
        return [];
    }
}
