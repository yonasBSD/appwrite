from appwrite.client import Client
from appwrite.services.graphql import Graphql

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

graphql = Graphql(client)

result = graphql.mutation(
    query = {}
)
