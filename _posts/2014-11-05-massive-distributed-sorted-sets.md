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
  end.
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

Using these data structures, we are storing 1000 `entry_sets` in our `manifest`, and 1000 individual scores and usernames in each `entry_set`. The limit of 1000 is somewhat arbitrary, but most of the time, you wouldn't want to sort an unordered set of more than that many individual entries for performance reasons. With a set up like this, you could store up to 1 million individual scores in sorted order without the use of an external index.

## Algorithms


### Writing a new score

The pseudo code for adding a new score to the overall list then looks something like this:

```
# Get the corresponding `entry_set` for a given score, and add the new score to that `entry_set`
add_score(Game, User, Score) ->
  CurrentManifest = get(Game ++ "_manifest"),
  SortedEntrySetMetadata = get_sorted_entry_set_metadata_from_manifest(CurrentManifest),
  EntrySetId = find_entry_set_id_for_score(SortedEntrySetMetadata, Score, ""),

  EntrySet = get(EntrySetId),

  NewEntrySet = append_score_to_entry_set(EntrySet, User, Score),
  put(EntrySetId, NewEntrySet).

# Iterate over a sorted set of metadata to find an appropriate `entry_set` id for a score
find_entry_set_id_for_score([], _) ->
  {error, need_to_create_new_entry_set};

find_entry_set_id_for_score([Metadata | RestOfMetadata], Score) ->
  LowestScore = get_lowest_score_from_metadata(Metadata),
  if
    Score > LowestScore ->
      EntrySetId = get_entry_set_id_from_metadata(Metadata),
      # Exit recursion and return the found EntrySetId
      EntrySetId;
    true ->
      find_entry_set_id_for_score(RestOfMetadata, Score)
  end.
```

In plain english, the goal of the above code reads like so:

* Get the current `manifest` which contains a list of all `entry_sets` as well as the lowest scores in each `entry_set`
* Sort the list of `entry_sets`
* Iterate over the `entry_sets` until we find one with a score lower than the one that we want to store
* Append the new score to that `entry_set` and store it in the datastore
* If the resulting `entry_set` exceeds the limit of 1000 scores:
  * Divide the `entry_set` into two separate `entry_sets`
  * Delete the old one
  * Update the `manifest` by removing the old `entry_set` metadata and include the two ones

### Reading the top 10 scores

In order to read an arbitrary number of scores from an arbitrary position in the total set of scores, follow these steps:

* Get the current `manifest` which contains a list of all `entry_sets` as well as the length of each `entry_set`
* Sort the list of `entry_sets`
* Iterate over the `entry_sets` while adding up the length of each `entry_set` until the `entry_set` at the given offset is found
* Fetch the `entry_set` at the correct offset, sort the list of scores found in the `entry_set`
* Return the number of desired scores in sorted order from the exact offset provided
  * It is possible that the number of requested scores spans multiple `entry_sets` - in this case more than one `entry_set` would need to be fetched to complete the query results given back to the user


# Solution 2a: What about billions of scores?

Solution 2 defined above works in theory for up to 1 million scores given our constraint of 1000 `entry_sets` per `manifest` and 1000 scores per `entry_set`. To go beyond that limit, it is simply a matter of adding additional layers of `manifests`. Here are a few example data structures for 1 billion total scores:

## Data Structures

### Entry Set

Key: `game_entry_set_<arbitrary_guid_1>`, Value:

```
[
  {"user": "han", "score": 100000},
  ...
  {"user": "r2-d2", "score": 999000}
]
```

### Manifest

Key: `game_manifest_<arbitrary_guid_1>`, Value:

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

### Manifest of Manifests

Key: `game_master_mainfest`, Value:

```
{
  "lowest_score": 100
  "manifests": [
    {"manifest_id": "game_manifest_<arbitrary_guid_1>", "length": 1000, "lowest_score": 999000000},
    {"manifest_id": "game_manifest_<arbitrary_guid_2>", "length": 1000, "lowest_score": 999800000},
    ...
    {"manifest_id": "game_manifest_<arbitrary_guid_1000>", "length": 10, lowest_score": 100}
  ]
}
```

## Algorithms

The method of storing and retrieving scores for multiple layers of `manifests` is essentially the same as solution 2 above with additional recursion once the top level `manifests` are located.

Each additional layer of `manifests` added has an impact of sorting an additional 1000 members of an unordered set, as well as an additional read from the datastore.

In solution 2a with a single `master_manifest` of `manifests`, the process to store new score at a high level looks like this:

* Fetch and sort `master_manifest` of `manifests`
* Iterate over each `manifest` until the one that the new score belongs to is found using the `lowest_score` recorded for each `manifest`
* Fetch and sort `manifest` of `entry_sets`
* Iterate over each `entry_set` until the one that the new score belongs to is found using the `lowest_score` recorded for each `entry_set`
* Fetch and append the new score to that `entry_set`
  * If the number of scores in the `entry_set` exceeds 1000, split it into two `entry_sets` and update the `manifest`
    * If the number of `entry_sets` in the `manifest` exeeds 1000, split it into two `manifests` and update the `master_manifest`