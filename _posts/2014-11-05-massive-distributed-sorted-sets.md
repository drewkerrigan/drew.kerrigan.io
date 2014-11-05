---
layout: post
title: Massive Distributed Sorted Sets
permalink: "massive-distributed-sorted-sets"
---

How to create a leaderboard at scale or other massive distributed sorted sets.

This post is heavily influenced by [https://github.com/clr](https://github.com/clr) and his repository found here: [https://github.com/clr/massive\_distributed\_sorted\_set](https://github.com/clr/massive_distributed_sorted_set)

# The Problem

Consider the following use-case: A video game backend needs to track high scores for every user and display a leaderboard for those users. There are two main flavors of this problem that impact the resulting solution.

1. Display only the top 10 scores of all time
2. Display a user's overall ranking as well as arbitrary pagination of the entire list

# Solution 1: Display only the top 10 scores of all time

This problem turns out to be fairly easy with map and set data structures like Riak's: [http://docs.basho.com/riak/latest/theory/concepts/crdts/#Riak-s-Five-Data-Types](http://docs.basho.com/riak/latest/theory/concepts/crdts/#Riak-s-Five-Data-Types).

## Data Structures

The map including a nested set might look something like this for a populated top 10 set:

Key: `game_top_10`, Value: 

```
{
  "lowest_score": 90
  "scores": [
    {"user": "vader", "score": 99},
    {"user": "yoda", "score": 98},
    {"user": "boba", "score": 97},
    {"user": "luke", "score": 96},
    {"user": "leia", "score": 95},
    {"user": "palpatiner", "score": 94},
    {"user": "obi-wan", "score": 93},
    {"user": "jabba", "score": 92},
    {"user": "r2-d2", "score": 91},
    {"user": "chewbacca", "score": 90}
  ]
}
```

## Algorithms

The pseudo code for accepting a new score might look like this:

```
update_score(Game, User, Score) ->
  CurrentScores = get(Game ++ "_top_10"),
  LowestScore = get_lowest_score_from_map(CurrentScores),

  if
    Score > LowestScore ->
      NewScores = remove_lowest_score_and_add_new_score(CurrentScores, User, Score),
      put(Game ++ "_top_10", NewScores);
    true ->
      false
end
```

Given our existing top 10 score list above, if we were to call update_score like so:

```
update_score("game", "han", 100).
```

The resulting value for `game_top_10` in our datastore would look like this:

```
{
  "lowest_score": 91
  "scores": [
    {"user": "han", "score": 100},
    ...
    {"user": "r2-d2", "score": 91}
  ]
}
```

Because it may be possible to have multiple actors updating the top 10 scores concurrently, we need to have logic on read to ensure the following:

* Sort the list of scores in memory
* If there are more than 10 entries in the set:
  * remove the lowest scores so that 10 remain
  * update the lowest_score entry and save the value

# Solution 2: Display a user's overall ranking as well as arbitrary pagination of the entire list

The solution to this problem is a little more tricky when we take into account the possibility of millions of users, or even billions or trillions of individual scores that need to be tracked over the lifetime of a game.

The above solution can be reused to scale the number of scores to much higher volumes. We'll call a single set of scores (like the one above) an `entry_set`. In order to keep and make sense of multiple `entry_sets`, a `manifest` is required to keep track of the `entry_set` ids.

## Data Structures

Let's start with an `entry_set` of 1000 scores:

Key: `game_entry_set_<arbitrary_guid_1>`, Value:

```
[
  {"user": "han", "score": 100000},
  ...
  {"user": "r2-d2", "score": 999000}
]
```

Now the interesting part, the `manifest` which keeps track of multiple `entry_sets`:

Key: `game_manifest`, Value: 

```
{
  "lowest_score": 100
  "entry_sets": [
    {"entry_set_id": "game_entry_set_<arbitrary_guid_1>", "length": 1000, "lowest_score": 999000},
    {"entry_set_id": "game_entry_set_<arbitrary_guid_2>", "length": 1000, "lowest_score": 998000},
    ...
    {"entry_set_id": "game_entry_set_<arbitrary_guid_1000>", "length": 400, lowest_score": 100}
  ]
}
```

Using these data structures, we are storing 1000 `entry_sets` in our `manifest`, and 1000 individual scores and usernames in each `entry_set`. The limit of 1000 is somewhat arbitrary, but most of the time, you wouldn't want to sort an unordered set of more than that many individual entries for performance reasons. With a set up like this, you could store up to 1 million individual scores in sorted order without using any kind of external index

## Algorithms

The pseudo code for adding a new score to the overall list then looks something like this:

```

```

![](/media/screenshot.jpg)