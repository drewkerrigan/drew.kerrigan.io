---
layout: post
title: Massive Distributed Sorted Sets
permalink: "massive-distributed-sorted-sets"
---

How to create a leaderboard at scale or other massive distributed sorted sets.

This post is heavily influenced by [https://github.com/clr](https://github.com/clr) and his repository found here: [https://github.com/clr/massive\_distributed\_sorted\_set](https://github.com/clr/massive_distributed_sorted_set)

# The Problem

Consider the following use-case: A video game backend needs to track high scores for every user and display a leaderboard for those users. There are two main flavors of this problem that impact the resulting solution.

## 1) Display only the top 10 scores of all time

This problem turns out to be fairly easy with map and set data structures like Riak's: [http://docs.basho.com/riak/latest/theory/concepts/crdts/#Riak-s-Five-Data-Types](http://docs.basho.com/riak/latest/theory/concepts/crdts/#Riak-s-Five-Data-Types).

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

## 2) Display a user's overall ranking as well as arbitrary pagination of the entire list

The solution to this problem is a little more tricky when we take into account the possibility of millions of users, or even billions or trillions of individual scores that need to be tracked over the lifetime of a game.

![](/media/screenshot.jpg)