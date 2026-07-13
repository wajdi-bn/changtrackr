import { useDeferredValue, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { App, Button, Empty, Input, Skeleton } from 'antd'
import { Gauge, MapPin, Navigation, Search, Zap } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MountainBanner } from '../components/MountainBanner'
import { startChargingSession } from '../features/charging/chargingApi'
import { StartSessionDrawer } from '../features/charging/StartSessionDrawer'
import { getStations } from '../features/stations/stationApi'

export function FindStationPage() {
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [selectedStationId, setSelectedStationId] = useState<number | null>(null)
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { message } = App.useApp()
  const stationsQuery = useQuery({ queryKey: ['stations', 'client-finder'], queryFn: () => getStations({}) })
  const stations = useMemo(() => (stationsQuery.data?.data ?? []).filter((station) => {
    const term = deferredSearch.trim().toLowerCase()
    return station.available_connectors_count > 0 && (!term || `${station.name} ${station.city} ${station.location_name} ${station.organization?.name ?? ''}`.toLowerCase().includes(term))
  }), [deferredSearch, stationsQuery.data?.data])
  const startMutation = useMutation({
    mutationFn: startChargingSession,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['charging-sessions'] })
      await queryClient.invalidateQueries({ queryKey: ['stations'] })
      void message.success('Charging session started.')
      navigate('/my-sessions')
    },
    onError: () => void message.error('The connector is no longer available.'),
  })

  return <div className="find-station-page">
    <MountainBanner color="green" breadcrumb={['Driver', 'Find station']} title="Find an available station" count={stations.length} subtitle="Compare connector availability and power, then start charging without leaving the platform." />
    <div className="station-finder-toolbar"><Input value={search} onChange={(event) => setSearch(event.target.value)} prefix={<Search size={15} />} placeholder="Search by station, city, or location" allowClear /><Button icon={<Navigation size={15} />}>Near me</Button></div>
    {stationsQuery.isLoading ? <div className="finder-grid">{Array.from({ length: 6 }, (_, index) => <Skeleton key={index} active />)}</div> : stations.length === 0 ? <Empty description="No available connector matches your search" /> : (
      <div className="finder-grid">{stations.map((station) => <article className="finder-card" key={station.id}>
        <img src={station.model_image ?? '/assets/charger-terra-hp-150.png'} alt={`${station.name} charging station`} />
        <div className="finder-card-body">
          <div className="finder-card-title"><div><h2>{station.name}</h2><small>{station.organization?.name ?? 'Charging network'}</small><p><MapPin size={13} />{station.location}</p></div><strong>{station.available_connectors_count} available</strong></div>
          <div className="finder-card-facts"><span><Zap size={14} /><b>{station.max_power_kw} kW</b>Maximum power</span><span><Gauge size={14} /><b>{station.uptime_percent}%</b>Uptime</span></div>
          <div className="finder-connectors">{station.connectors.filter((connector) => connector.status === 'available').slice(0, 4).map((connector) => <span key={connector.id}>{connector.external_id} - {connector.type}</span>)}</div>
          <Button type="primary" block onClick={() => setSelectedStationId(station.id)}>Start charging here</Button>
        </div>
      </article>)}</div>
    )}
    <StartSessionDrawer open={selectedStationId !== null} stations={stationsQuery.data?.data ?? []} initialStationId={selectedStationId} submitting={startMutation.isPending} onClose={() => setSelectedStationId(null)} onSubmit={(payload) => startMutation.mutate(payload)} />
  </div>
}
