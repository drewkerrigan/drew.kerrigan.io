---
layout: post
title: KV Data Modeling - Time Series
permalink: "riak-data-modeling-time-series"
author: 
    name: "Drew Kerrigan"
summary: Modeling time series data in a KV store
---

# KV Data Modeling: Time Series

## What is it?

Time Series data is proliferating with literally every step that we take, just think about things like Fitbit bracelets that track your every move and financial trading data all of which is timestamped. 

Time series data requires high performance reads and writes even with a huge number of data sources. Both speed and scale are integral to success, which makes for a unique challenge for your database. 

A time series NoSQL data model requires flexibility to support unstructured, and semi-structured data as well as the ability to write range queries to analyze your time series data.

### Dataset

Hard drive test data: [https://www.backblaze.com/hard-drive-test-data.html](https://www.backblaze.com/hard-drive-test-data.html)

Lets walk through a sample data exercise. We have access to a fairly large amount of hard drive test data, and you can download the data using the link above. Included in that data is the S.M.A.R.T. stats, or “Self-Monitoring, Analysis and Reporting Technology” for each of the drives tested (a number of columns of data with information about a Hard Drive’s performance).

So, What questions might we ask about this data?

## Data Characteristics

If we were encountering the data over time, and not just all at once, what decisions would we make regarding the storage of that data? Lets look at the characteristics of the raw data:

```
[Date, Serial Number, Model, Capacity (bytes), Failure, …, smart_194_raw (Temp), …]

Sample Row:
Date: “2013-04-10”
Model: “Hitachi HDS5C3030ALA630”
Failure: 0
Temp: 26
```

Given our question: “What is the effect of temperature on hard drive failures?”, We need to look for potential values in our data other than the timestamp that would be good candidates to index on.
This is a potentially big decision depending on the amount of data we plan to ingest.

If we choose the Model name as part of our composite primary partition key, we are effectively limiting ourselves to performing calculations on a single Model at a time. On the other hand, if we know that we only have maybe 20 or 30 different models of hard drives, that might not be such a bad thing since all of the data for a single Model would then be colocated physically on disk, giving us quicker queries for that group of data.

## Define the Conceptual Query

Now that we have some questions to ask of the data, and a good idea of what that data looks like, we can start to conceptualize what the query will look like. There are a few ways that we can tackle this problem, here’s one approach for what the query might look like:

```
SELECT * FROM HardDrives
WHERE date >= 2013-01-01 
AND date <= 2013-12-31
AND failure = 'true’
```

The plain english for this query is “Find all of the hard drives that failed in 2013”
This means that we essentially only choose to index on the date on which a failure happened, and whether or not a failure was encountered on that date

The advantage here is that all of the data for failures is colocated physically,
But it also means more client side work to actually get the numbers that we want.

## Create the Table

Using Riak TS, here's what the CREATE TABLE command might look like:

```
riak-admin bucket-type create HardDrives '{"props":{"n_val":3, "table_def":”
  CREATE TABLE HardDrives (
    date TIMESTAMP NOT NULL, 
    family VARCHAR NOT NULL, 
    failure VARCHAR NOT NULL, 
    serial VARCHAR, 
    model VARCHAR, 
    capacity FLOAT, 
    temperature FLOAT, 
    PRIMARY KEY (
      (quantum(date, 1, ‘y'), family, failure), 
       date, family, failure))"}}’
```

Table definitions in Riak TS are created using the riak-admin bucket-type create command as shown here. You’ll notice that I added a field named “family,” this is because of the way that Riak TS creates the composite keys for the data.

In the last section of the create table statement, you can see the PRIMARY KEY definition which has 2 sections, a little background: In normal Riak, the key is used to determine both where the data is placed in the cluster and how the data is accessed on the physical disk using a consistent hashing algorithm. In Riak TS, however, we have the concept of two keys: The first is a partition key which determines where the data is placed on the cluster, and the second is a local key which determines how the data is actually stashed on the disk.

The first section with the “Quantum” function defines the “Partition Key” which defines where on the ring or cluster the data will live. The quantum function, or time quantisation, is just saying “group the data in 15 minute clumps, or 10 second clumps, or 2 month clumps” depending on the ingestion characteristics of the time series data. This is important, because each chunk of requires a different sub-query to be run on a different section of the ring! Riak TS will actually prevent you from running a query that generates too many of these sub queries, so to make sure you are going to be able to run queries that span a big enough time frame, an appropriate quantum should be chosen. In the example for Hard Drive data, I chose a 1 year chunk because the finest granularity is at the day level, and I wanted to be able to easily query over a full year of data.

The second part of the partition key is what we call the “family”. This is what will be used in the future for expiry of data based on the group it belongs to. For this approach, all of the data can be considered as part of the same family, so I’ll just be hardcoding it to the string “all”.

The third part of the key is called the “series” or the time series set. you should use a column from your data that you intend to query on for this section of the key. I know that I’m interested in grouping up all rows with a failure status, so I’m including it as part of the composite primary key.

The second section that just lists “date, family, and failure” is the “Local Key” definition. This determines how the data is actually stored on disk, and at this point, it needs to be the same 3 fields as the partition key in the same order. The final step not shown here is the same step needed for any other bucket-type in Riak which is “riak-admin bucket-type activate.”

## Ingest the Data

Now for the actual Erlang code to write a row to our HardDrives table:

```
RawRow = [
	<<“2013-04-10”>>, %% Date
	<<“MJ0351YNG9Z0XA”>>, %% Serial
	<<“Hitachi HDS5C3030ALA630”>>, %% Model
	<<“3000592982016”>>, %% Capacity
	<<“0”>>, %% Failure
	…, <<“26”>>, …], %% SMART Stats with Temperature 

ProcessedRow = [
		1365555661000, %% Date
		<<“all”>>, %% Family
		<<“false”>>, %% Failure
		<<“MJ0351YNG9Z0XA”>>, %% Serial
		<<“Hitachi HDS5C3030ALA630”>>, %% Model
		3000592982016.0, %% Capacity
		26.0], %% Temperature

riakc_ts:put(Pid,<<"HardDrives">>,[ProcessedRow]).
```

After processing the raw data, we can call the “put” function from the riak erlang client TS module giving it:

* A Process ID for our connection to the Riak Protobuf interface,
* The name of our table created before,
* And the list of rows to insert.

As a side note,  this example only shows inserting a single row, but you can actually batch insert more than one row at a time using this function.

## Query the Data

Ok, the hard drive data is in Riak TS, and we’d like to get it back out. The Query language supported by Riak TS is a subset of SQL, and support for new features is growing as development continues. Here we’re selecting all fields, and filtering on:

* A date range which is the entire year of 2013
* The “all” family of data
* And we only care about rows with failure = true

To execute the search, we call the “query” function from the riak erlang client’s TS module giving it:

* The Process ID or PID again with the connection to Riak
* And our query as a binary string

```
Start = integer_to_list(date_to_epoch_ms(<<"2013-01-01">>)),
End = integer_to_list(date_to_epoch_ms(<<"2013-12-31">>)),

Query = "select * from HardDrives 
	where date >= " ++ Start ++ " 
	  and date <= " ++ End ++ " 
	  and family = 'all' 
	  and failure = 'true'",

{_Fields, Results} = 
	riakc_ts:query(Pid, list_to_binary(Query)),
```

The query function will return a tuple consisting of:

* The field names which is all of them in this case, and
* A list of results matching our query

Results:

```
Total Failures: 112
Results: 
	[{
		1365555661000,
		<<"all">>,
		<<"true">>,
		<<"9VS3FM1J">>,
		<<"ST31500341AS">>,
		1500301910016.0,
		31.0
	 },
	 {...},
	 {...},
	  ...
	]

```

Because we chose to really only index on whether or not a row has the failure state or not, we need to do some processing of the results to get the data needed for a graph of the effect of temperature on hard drive failures.
So we just need to iterate over all of the results on the client side, and aggregate the failures by temperature, and then group those stats up by model name.

```
"ST31500341AS": ...
"ST3000DM001": ...
"Hitachi HDS5C4040ALE630": ...
"ST4000DM000": ...
"ST31500541AS": 
	18.0=1 19.0=1 20.0=2 21.0=3 22.0=2
	24.0=2 25.0=1 29.0=3 30.0=1
```

We can see that in 2013, there were actually only 112 failures recorded, so it’s obviously difficult to make any kind of statistical observations with that number of records. We can see that the failures are actually more weighted around the center of the temperature range for the 2013 data, but there’s nothing statistically significant in the 2013 dataset.

## Summary

In Riak TS, the keys are automatically created for you based on the contents of your data as well as how you defined your table. A query language that implements a subset of SQL makes range queries very simple.

Compound query capabilities are a direct result of the choices you make when designing the table definition and PRIMARY KEY, but more filtering operations will be added in the future. 

Data locality is an amazing addition to Riak, which opens up performance capabilities that just weren’t possible before for certain data access patterns.

As a bonus, in addition to the server side multi-gets on the query side, the write or put function in Riak TS allows you to insert multiple rows at once, giving the option to create batches now.

As you think about your data in the mindset of the capabilities and strengths of the data store, you will get more creative with the solutions you architect.