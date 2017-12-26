<?php

namespace App\Events;

use App\Events\Event;
use App\Link;
use App\User;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class UserLinkEvent extends Event
{
    use SerializesModels;

    public $link;
    public $user;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Link $link, $user)
    {
        $this->link = $link;
        $this->user = $user;
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