---
layout: post
title: Custom Data Extractors for Riak Search
permalink: "custom-data-extractors-for-riak-search"
author: 
    name: "Drew Kerrigan"
---

How to index custom data types with Riak Search.

# Custom Yokozuna Extractors

In this post we are going to create a custom yokozuna extractor for Riak Search (version 2.x). The code and a shorter readme can be found here [https://github.com/drewkerrigan/riak_sandbox/tree/master/search](https://github.com/drewkerrigan/riak_sandbox/tree/master/search). More information about yokozuna extractors can be found here: [https://github.com/basho/yokozuna/blob/develop/docs/CONCEPTS.md#extractors](https://github.com/basho/yokozuna/blob/develop/docs/CONCEPTS.md#extractors).

## The Data

To keep this guide simple, we are going to create a simple extractor which allows us to index the interesting pieces of an HTTP header packet.

Here is an example representation of an HTTP header:

```
GET http://www.google.com HTTP/1.1

```

## Yokozuna Text Extractor

Custom yokozuna extractors have a very simple interface that must be implemented (in Erlang). Here is what the pure text extractor looks like:

```
-module(yz_text_extractor).
-include("yokozuna.hrl").
-compile(export_all).

extract(Value) ->
    extract(Value, []).

extract(Value, Opts) ->
    FieldName = field_name(Opts),
    [{FieldName, Value}].

-spec field_name(proplist()) -> any().
field_name(Opts) ->
    proplists:get_value(field_name, Opts, text).
```

This extractor simply takes the contents of `Value` and returns a proplist with a single field name and the single value associated with that name. By default, the field name is `text`. If the following erlang snippet were run in a Riak console session:

```
yz_text_extractor:extract("hello").
```

The output would look something like this:

```
[{text,"hello"}]
```

That proplist is handed off to Solr, and the value `"hello"` would be indexed under the fieldname `text`.

## Custom Binary Extractor

Back to our example of parsing a binary HTTP header packet; Erlang luckily comes with a standard packet decoder that happens to handle HTTP packets:

```
erlang:decode_packet(http,<<"GET http://www.google.com HTTP/1.1\n">>,[]).
```

That snippet should return something like this:

```
{ok,{http_request,'GET',
                  {absoluteURI,http,"www.google.com",undefined,"/"},
                  {1,1}},
    <<>>}
```

The relevant bits to an application that needed to search these packets are probably the Method (`GET`), the Host (`www.google.com`), and the Uri (`/`).

Using the text extractor as an example, our custom extractor should look similar to this if we want to index those 3 fields:

`yz_httpheader_extractor.erl`

```

-module(yz_httpheader_extractor).
-compile(export_all).

extract(Value) ->
    extract(Value, []).

extract(Value, _Opts) ->
    {ok,{http_request,Method,
            {absoluteURI,http,Host,undefined,Uri},
            _Version},
        _Rest} = erlang:decode_packet(http,Value,[]),

    [{method, Method}, {host, list_to_binary(Host)}, {uri, list_to_binary(Uri)}].
```

## Register the Custom Extractor

Writing the extractor was simple enough, but in order for it to be utilized by Riak Search, a few steps need to be taken:

### Compile the Extractor

Firstly we'll need to compile the extractor into a `beam` file and distribute it to a path that we can remember on each Riak node.

```
erlc yz_httpheader_extractor.erl
```

Move the resulting beam file to a directory like `/opt/beams`

```
mv yz_httpheader_extractor.beam /opt/beams/
```

### Configure Riak

We'll need to tell Riak where to find the new beam file. This cannot currently be done using `riak.conf`, but there is a workaround if you create a file called `advanced.config` in the same directory as your `riak.conf`

`/etc/riak/advanced.config`

```
[{vm_args, [{"-pa /opt/beams",""}]}].
```

This vm.args directive tells Riak to add `/opt/beams` to the erlang path when starting Riak up.

### Register the Extractor in Riak

```
riak start
riak attach
```

This should log into the running Riak node allowing us to run the register function in `yz_extractor`: 

```
(riak@127.0.0.1)1> yz_extractor:register("application/httpheader", yz_httpheader_extractor).
```

The register call should return the updated list of mimetype -> extractor mappings. It should look something like this:

```
[{default,yz_noop_extractor},
 {"application/httpheader",yz_httpheader_extractor},
 {"application/json",yz_json_extractor},
 {"application/riak_counter",yz_dt_extractor},
 {"application/riak_map",yz_dt_extractor},
 {"application/riak_set",yz_dt_extractor},
 {"application/xml",yz_xml_extractor},
 {"text/plain",yz_text_extractor},
 {"text/xml",yz_xml_extractor}]
```

Now, any new documents submitted to yokozuna with the content type `application/httpheader` should be run through the new extractor.

The new extractor can be verified using the yokozuna `extract` endpoint:

Create a file called `testdata.bin`

`testdata.bin`

```
GET http://www.google.com HTTP/1.1

```

(Note the trailing newline at the end)

Now run a `PUT` to the extract endpoint:

```
curl -XPUT -H 'content-type: application/httpheader' 'http://localhost:8098/search/extract' --data-binary "@testdata.bin"
```

That curl call should return this JSON:

```
{"method":"GET","host":"www.google.com","uri":"/"}
```

The new extractor can also be verified in the Riak console:

```
(riak@127.0.0.1)1> yz_extractor:run(<<"GET http://www.google.com HTTP/1.1\n">>, yz_httpheader_extractor).
```

Which should return

```
[{method,'GET'},{host,<<"www.google.com">>},{uri,<<"/">>}]
```

## Index and Search for the Data

### Create Schema

(Based on default Yokozuna Solr Schema with our own field definitions, the default schema can be found here: [https://raw.githubusercontent.com/basho/yokozuna/develop/priv/default_schema.xml](https://raw.githubusercontent.com/basho/yokozuna/develop/priv/default_schema.xml))

Create a `my_schema.xml` based on the default schema:

```
...
<field name="method" type="string" indexed="true" stored="true" multiValued="false"/>
<field name="host" type="string" indexed="true" stored="true" multiValued="false"/>
<field name="uri" type="string" indexed="true" stored="true" multiValued="false"/>
...
```

Store the schema

```
curl -XPUT "http://localhost:8098/search/schema/my_schema" \
  -H 'content-type:application/xml' \
  --data-binary @my_schema.xml
```

### Create a search index using your schema

```
curl -XPUT "http://localhost:8098/search/index/my_index" \
     -H'content-type:application/json' \
     -d'{"schema":"my_schema"}'
```

### Create a bucket type so that multiple buckets can share an index

```
riak-admin bucket-type create my_type '{"props":{"search_index":"my_index"}}'
riak-admin bucket-type activate my_type
```

### Store Some Data

Use the `testdata.bin` file we created earlier to write data to Riak:

```
curl -XPUT \
  -H "Content-Type: application/httpheader" \
  --data-binary "@testdata.bin" \
  http://localhost:8098/types/my_type/buckets/headers/keys/google
```

### Query the Data

```
curl 'http://localhost:8098/search/query/my_index?wt=json&q=method:GET'
```

And if everything is successful, we should see our record returned in the results!

```
{
    "response": {
        "docs": [
            {
                "_yz_id": "1*my_type*headers*google*15",
                "_yz_rb": "headers",
                "_yz_rk": "google",
                "_yz_rt": "my_type",
                "host": "www.google.com",
                "method": "GET",
                "uri": "/"
            }
        ],
        "maxScore": 0.71231794,
        "numFound": 1,
        "start": 0
    },
    "responseHeader": {
        "QTime": 8,
        "params": {
            "127.0.0.1:8093": "_yz_pn:64 OR (_yz_pn:61 AND (_yz_fpn:61)) OR _yz_pn:60 OR _yz_pn:57 OR _yz_pn:54 OR _yz_pn:51 OR _yz_pn:48 OR _yz_pn:45 OR _yz_pn:42 OR _yz_pn:39 OR _yz_pn:36 OR _yz_pn:33 OR _yz_pn:30 OR _yz_pn:27 OR _yz_pn:24 OR _yz_pn:21 OR _yz_pn:18 OR _yz_pn:15 OR _yz_pn:12 OR _yz_pn:9 OR _yz_pn:6 OR _yz_pn:3",
            "q": "method:GET",
            "shards": "127.0.0.1:8093/internal_solr/my_index",
            "wt": "json"
        },
        "status": 0
    }
}
```