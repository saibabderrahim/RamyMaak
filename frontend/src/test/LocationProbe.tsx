import { useLocation } from 'react-router'

export function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location">{location.pathname}</div>
}
